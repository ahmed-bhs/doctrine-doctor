<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\CollectionJoinDetector;
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Detects queries using LIMIT (from setMaxResults) with collection joins.
 * This is a critical anti-pattern because:
 * - LIMIT applies to SQL rows, not to entities
 * - When joining collections (OneToMany, ManyToMany), one entity can span multiple rows
 * - This leads to partially hydrated collections (missing data)
 * - Data loss is silent and hard to detect
 * Example problem:
 * ```php
 * $qb->select('pet')
 *    ->addSelect('pictures')
 *    ->from(Pet::class, 'pet')
 *    ->leftJoin('pet.pictures', 'pictures')
 *    ->setMaxResults(1);
 * ```
 * If Pet has 4 pictures, only 1 picture will be loaded due to LIMIT 1.
 * The severe variant adds setFirstResult() to build a batch loop:
 * ```php
 * $total = $repository->count([]);
 * while ($offset < $total) {
 *     $rows = $qb->leftJoin('r.tags', 't')->addSelect('t')
 *         ->setFirstResult($offset)->setMaxResults(200)->getQuery()->getResult();
 *     $offset += 200;
 * }
 * ```
 * Here OFFSET walks over joined rows while $total counts root entities. With a
 * row multiplication factor above 1 the loop exits after the first pass and the
 * remaining root entities are never read at all: whole entities disappear, not
 * just collection items.
 * Solution: paginate on root identifiers, then fetch-join that batch
 * ```php
 * $ids = $qb->select('r.id')->setFirstResult($offset)->setMaxResults(200)
 *     ->getQuery()->getSingleColumnResult();
 * $rows = $qb2->leftJoin('r.tags', 't')->addSelect('t')
 *     ->where('r.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
 * ```
 * Or use Doctrine's Paginator, which issues the identifier query for you
 * ```php
 * $paginator = new Paginator($query, $fetchJoinCollection = true);
 * ```
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/current/tutorials/pagination.html
 */
class SetMaxResultsWithCollectionJoinAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly SqlStructureExtractor $sqlExtractor,
        private readonly CollectionJoinDetector $collectionJoinDetector,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        // Article pattern: Use generator instead of array
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                foreach ($queryDataCollection as $queryData) {
                    if (!$queryData->isSelect()) {
                        continue;
                    }

                    if ($this->hasLimitWithFetchJoin($queryData->sql)) {
                        yield $this->createIssue($queryData);
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'setMaxResults with Collection Join Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects queries using setMaxResults() with collection joins, which causes partial collection hydration';
    }

    /**
     * LIMIT with a fetch join that can return several rows per root entity.
     *
     * Each fetch-joined table (one whose columns are selected) is checked on its own: the warning is silenced
     * only when every one of them yields at most one row per root entity. A grouped query returns one row per
     * group, so its LIMIT counts groups and is left alone.
     */
    private function hasLimitWithFetchJoin(string $sql): bool
    {
        if (!$this->sqlExtractor->hasLimit($sql) || !$this->sqlExtractor->hasJoin($sql) || $this->sqlExtractor->hasGroupBy($sql)) {
            return false;
        }

        // The SELECT extractor strips the trailing underscore of Doctrine aliases ("t1" for "t1_").
        $normalize = static fn (string $alias): string => rtrim(strtolower($alias), '_');
        $selectedAliases = array_values(array_unique(array_map($normalize, $this->sqlExtractor->extractTableAliasesFromSelect($sql))));
        if (count($selectedAliases) < 2) {
            return false;
        }

        $joins = $this->sqlExtractor->extractJoins($sql);
        $fetchJoins = array_filter($joins, static fn (array $join): bool => \is_string($join['alias']) && \in_array($normalize($join['alias']), $selectedAliases, true));

        // Besides the root, a selected alias that no readable join explains: keep the warning, it is about data loss.
        $explained = array_map(static fn (array $join): string => $normalize((string) $join['alias']), $fetchJoins);
        if (count(array_diff($selectedAliases, $explained)) > 1) {
            return true;
        }
        return array_any($fetchJoins, fn ($join) => !$this->isSingleRowJoin($sql, $join));
    }

    /**
     * @param array{type: string, table: mixed, alias: mixed, expr: mixed} $join
     */
    private function isSingleRowJoin(string $sql, array $join): bool
    {
        $table = \is_string($join['table']) ? $join['table'] : '';
        $alias = \is_string($join['alias']) ? $join['alias'] : '';
        $condition = $this->joinCondition($join['expr']);

        // Translation tables: one row per locale.
        if (1 === preg_match('/\blocale\b\s*(?:=|IN\b)/i', $condition)) {
            return true;
        }

        if ($this->isConfirmedToOneFromRoot($sql, $table)) {
            return true;
        }

        $metadata = $this->collectionJoinDetector->buildMetadataMap()[$table] ?? null;
        if ($metadata instanceof ClassMetadata) {
            return $this->conditionCoversUniqueKey($metadata, $alias, $condition);
        }

        // Unmapped table: an extra equality or range on the joined row usually selects one row.
        return 1 === preg_match('/\bAND\b\s+\(?\s*' . preg_quote($alias, '/') . '\.\w+\s*(?:=|<=?|>=?)\s*\?/i', $condition);
    }

    private function isConfirmedToOneFromRoot(string $sql, string $joinTable): bool
    {
        $metadataMap = $this->collectionJoinDetector->buildMetadataMap();
        $fromTable = $this->collectionJoinDetector->extractFromTable($sql, $metadataMap);
        $fromMetadata = null !== $fromTable ? ($metadataMap[$fromTable] ?? null) : null;

        return $fromMetadata instanceof ClassMetadata && $this->isConfirmedToOneJoin($fromMetadata, $joinTable);
    }

    /**
     * The columns of the joined table compared in the ON clause cover its primary key or a unique constraint.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function conditionCoversUniqueKey(ClassMetadata $metadata, string $alias, string $condition): bool
    {
        preg_match_all('/\b' . preg_quote($alias, '/') . '\.(\w+)\s*=/i', $condition, $left);
        preg_match_all('/=\s*' . preg_quote($alias, '/') . '\.(\w+)\b/i', $condition, $right);
        $columns = array_map(strtolower(...), array_merge($left[1], $right[1]));

        $keys = [array_map($metadata->getColumnName(...), $metadata->getIdentifierFieldNames())];
        foreach ($metadata->table['uniqueConstraints'] ?? [] as $constraint) {
            $keys[] = $this->constraintColumns($constraint);
        }
        foreach ($metadata->fieldMappings as $mapping) {
            if (true === MappingHelper::getBool($mapping, 'unique')) {
                $keys[] = [(string) MappingHelper::getString($mapping, 'columnName')];
            }
        }

        foreach ($keys as $key) {
            $key = array_map(static fn (mixed $column): string => strtolower((string) $column), $key);
            if ([] !== $key && [] === array_diff($key, $columns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unique constraint columns: an array up to ORM 3.7, an IndexMapping object from ORM 3.8.
     *
     * @return list<string>
     */
    private function constraintColumns(mixed $constraint): array
    {
        $columns = match (true) {
            \is_array($constraint) => $constraint['columns'] ?? [],
            \is_object($constraint) && property_exists($constraint, 'columns') => $constraint->columns ?? [],
            default => [],
        };

        return \is_array($columns) ? array_values(array_map(static fn (mixed $column): string => \is_scalar($column) ? (string) $column : '', $columns)) : [];
    }

    private function joinCondition(mixed $expr): string
    {
        if (!\is_object($expr) || !property_exists($expr, 'on') || !\is_array($expr->on)) {
            return '';
        }

        return implode(' AND ', array_map(static fn (mixed $condition): string => \is_scalar($condition) || $condition instanceof \Stringable ? (string) $condition : '', $expr->on));
    }

    private function isConfirmedToOneJoin(ClassMetadata $fromMetadata, string $joinTable): bool
    {
        foreach ($fromMetadata->getAssociationMappings() as $associationMapping) {
            $targetEntity = $associationMapping->targetEntity;

            try {
                $targetMetadata = $this->entityManager->getClassMetadata($targetEntity);
            } catch (\Exception) {
                continue;
            }

            if ($targetMetadata->getTableName() !== $joinTable) {
                continue;
            }

            if (
                ClassMetadata::MANY_TO_ONE === $associationMapping->type()
                || ClassMetadata::ONE_TO_ONE === $associationMapping->type()
            ) {
                return true;
            }
        }

        return false;
    }

    private function createIssue(QueryData $queryData): IssueInterface
    {
        $tables    = $this->extractTableNames($queryData->sql);
        $mainTable = $tables[0] ?? 'entity';

        $issueData = new IssueData(
            type: IssueType::SET_MAX_RESULTS_WITH_COLLECTION_JOIN->value,
            title: 'setMaxResults() with Collection Join Detected',
            description: $this->buildDescription($queryData),
            severity: Severity::critical(),
            suggestion: $this->createSuggestion($mainTable),
            queries: [$queryData],
            backtrace: $queryData->backtrace,
        );

        return $this->issueFactory->create($issueData);
    }

    /**
     * Build the issue description: the measured LIMIT, join count and row count
     * when available, then the consequence. Works without a row count (rowCount
     * is nullable) and without OFFSET.
     */
    private function buildDescription(QueryData $queryData): string
    {
        $sql = $queryData->sql;
        $limit = $this->sqlExtractor->getLimitValue($sql);
        $joinCount = $this->sqlExtractor->countJoins($sql);
        $rowCount = $queryData->rowCount;

        if (null === $limit) {
            $fact = 'LIMIT is used with a fetch-joined collection, so it counts SQL rows, not root entities.';
        } elseif (null === $rowCount) {
            $fact = sprintf('LIMIT %d is applied across %d fetch-joined table(s), so it counts SQL rows, not root entities.', $limit, $joinCount);
        } else {
            $fact = sprintf('LIMIT %d is applied across %d fetch-joined table(s) and the database returned %d row(s), not %d root entities.', $limit, $joinCount, $rowCount, $limit);
        }

        if ($this->sqlExtractor->hasOffset($sql)) {
            $consequence = 'With a non-zero OFFSET, a paginated loop can skip whole root entities, not only collection items.';
        } elseif (null !== $limit && null !== $rowCount && $rowCount >= $limit) {
            $consequence = 'The result was truncated in the middle of a root entity, so its collection is partially hydrated (silent data loss).';
        } else {
            $consequence = 'Collections can be partially hydrated (silent data loss) and fewer root entities returned than expected.';
        }

        return $fact . ' ' . $consequence;
    }

    private function createSuggestion(string $entityHint): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/setMaxResults_with_collection_join',
            context: [
                'entity_hint'                => $entityHint,
                'offset_paginator_available' => $this->isOffsetPaginatorAvailable(),
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::critical(),
                title: 'Use Doctrine Paginator for Collection Joins with Limits',
                tags: ['critical', 'data-loss', 'pagination', 'collections', 'anti-pattern'],
            ),
        );
    }

    /**
     * OffsetPaginator replaces Paginator, deprecated since ORM 3.7.
     */
    private function isOffsetPaginatorAvailable(): bool
    {
        return class_exists(\Doctrine\ORM\Tools\Pagination\OffsetPaginator::class);
    }

    /**
     * Extract table names from SQL for better context in error messages.
     * Uses SQL parser instead of regex for robust extraction.
     * @return string[]
     */
    private function extractTableNames(string $sql): array
    {
        $allTables = $this->sqlExtractor->extractAllTables($sql);

        // Extract just the table names
        return array_map(fn ($table) => $table['table'], $allTables);
    }
}
