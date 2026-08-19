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
     * Detect if query has LIMIT with a fetch-joined collection.
     * Heuristics:
     * 1. Query must have LIMIT clause
     * 2. Query must have JOIN (LEFT JOIN or INNER JOIN)
     * 3. Query must SELECT columns from joined table (fetch join)
     * 4. JOIN must not have constraints that guarantee single row per entity
     *    (e.g., locale filters, unique constraints)
     */
    private function hasLimitWithFetchJoin(string $sql): bool
    {
        // Must have LIMIT - use SQL parser
        if (!$this->sqlExtractor->hasLimit($sql)) {
            return false;
        }

        // Must have JOIN
        if (!$this->sqlExtractor->hasJoin($sql)) {
            return false;
        }

        // Check if it's a fetch join (selecting from joined table)
        // Pattern: SELECT t0.col1, t1.col2 ... FROM table1 t0 JOIN table2 t1
        // If we select from both t0 and t1, it's a fetch join

        // Extract table aliases from SELECT using SQL parser
        $uniqueAliases = $this->sqlExtractor->extractTableAliasesFromSelect($sql);

        if ([] === $uniqueAliases) {
            return false;
        }

        // If selecting from multiple table aliases, it's likely a fetch join
        // (selecting both parent entity and joined collection)
        if (count($uniqueAliases) < 2) {
            return false;
        }

        // Check for safe patterns that indicate single-row joins
        // These patterns prevent false positives for translation tables, etc.
        if ($this->hasSingleRowJoinConstraint($sql)) {
            return false;
        }

        // Metadata-aware confirmation. Only a true to-many fetch-join
        // (OneToMany / ManyToMany) multiplies rows and makes LIMIT unsafe.
        // A to-one fetch-join (ManyToOne / OneToOne) yields one row per root
        // entity, so LIMIT is correct. The regex above cannot tell them apart.
        // We suppress ONLY when every fetch-joined table is positively confirmed
        // to-one by the mapping metadata (ground truth, order-independent). Any
        // join we cannot resolve (unmapped table, parse failure, to-many) keeps
        // the heuristic result, so we never silence a real data-loss warning.
        if ($this->allFetchJoinsAreConfirmedToOne($sql)) {
            return false;
        }

        return true;
    }

    private function allFetchJoinsAreConfirmedToOne(string $sql): bool
    {
        $metadataMap = $this->collectionJoinDetector->buildMetadataMap();
        $fromTable   = $this->collectionJoinDetector->extractFromTable($sql, $metadataMap);

        if (null === $fromTable) {
            return false;
        }

        $fromMetadata = $metadataMap[$fromTable] ?? null;

        if (null === $fromMetadata) {
            return false;
        }

        $joins = $this->sqlExtractor->extractJoins($sql);

        if ([] === $joins) {
            return false;
        }

        foreach ($joins as $join) {
            $joinTable = $join['table'];

            if (!is_string($joinTable) || !$this->isConfirmedToOneJoin($fromMetadata, $joinTable)) {
                return false;
            }
        }

        return true;
    }

    private function isConfirmedToOneJoin(ClassMetadata $fromMetadata, string $joinTable): bool
    {
        foreach ($fromMetadata->getAssociationMappings() as $associationMapping) {
            $targetEntity = $associationMapping['targetEntity'] ?? null;

            if (null === $targetEntity) {
                continue;
            }

            try {
                $targetMetadata = $this->entityManager->getClassMetadata($targetEntity);
            } catch (\Exception) {
                continue;
            }

            if ($targetMetadata->getTableName() !== $joinTable) {
                continue;
            }

            if (
                ClassMetadata::MANY_TO_ONE === $associationMapping['type']
                || ClassMetadata::ONE_TO_ONE === $associationMapping['type']
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect if JOIN has constraints that guarantee at most one row per parent entity.
     * Common patterns:
     * - Translation tables with locale filter: AND (t1_.locale = ?)
     * - Unique constraints on joined table
     * - Primary key equality in JOIN condition
     *
     * These patterns are safe with LIMIT because they don't create row multiplication.
     */
    private function hasSingleRowJoinConstraint(string $sql): bool
    {
        if ($this->sqlExtractor->hasLocaleConstraintInJoin($sql)) {
            return true;
        }

        if ($this->sqlExtractor->hasUniqueJoinConstraint($sql)) {
            return true;
        }

        if ($this->hasEqualityConstraintInJoinCondition($sql)) {
            return true;
        }

        return false;
    }

    private function hasEqualityConstraintInJoinCondition(string $sql): bool
    {
        return 1 === preg_match('/\bJOIN\b[^)]*\bON\b[^)]*\bAND\b\s+\w+\.\w+\s*(?:=|<=?|>=?)\s*\?/i', $sql);
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
     * Build the issue description, enriched with the measured facts of this
     * query whenever they are available.
     * The base text is always present so the message stays complete when the
     * profiler gives us no row count (rowCount is nullable) and when the query
     * carries no OFFSET.
     */
    private function buildDescription(QueryData $queryData): string
    {
        $sql = $queryData->sql;

        $lines = [
            'LIMIT is used with a fetch-joined collection.',
            'Impact: LIMIT is applied to SQL rows, not root entities.',
            'Impact: Collections may be partially hydrated (silent data loss).',
            'Impact: Result counts and application behavior may become incorrect.',
        ];

        foreach ($this->buildMeasuredFacts($queryData, $sql) as $fact) {
            $lines[] = $fact;
        }

        if ($this->sqlExtractor->hasOffset($sql)) {
            $lines[] = 'Impact: this query also carries a non-zero OFFSET, so it is part of a paginated '
                . 'walk. OFFSET counts rows, not root entities: a batch loop whose bound comes from a '
                . 'root-entity count can exit early and never read the remaining root entities at all. '
                . 'That loses whole entities, not just collection items.';
        }

        $lines[] = 'Fix: paginate on root entity identifiers, then fetch-join that batch '
            . '(WHERE id IN (:ids)), or wrap the query in Doctrine\ORM\Tools\Pagination\Paginator '
            . 'with $fetchJoinCollection = true.';

        return implode("\n", $lines);
    }

    /**
     * @return string[]
     */
    private function buildMeasuredFacts(QueryData $queryData, string $sql): array
    {
        $facts = [];
        $limit = $this->sqlExtractor->getLimitValue($sql);
        $joinCount = $this->sqlExtractor->countJoins($sql);
        $rowCount = $queryData->rowCount;

        if (null !== $limit) {
            $facts[] = sprintf('Observed: LIMIT %d applied across %d fetch-joined table(s).', $limit, $joinCount);
        }

        if (null === $limit || null === $rowCount) {
            return $facts;
        }

        $facts[] = sprintf('Observed: the database returned %d row(s) for this LIMIT %d.', $rowCount, $limit);

        if ($rowCount >= $limit) {
            $facts[] = sprintf(
                'Observed: the row count reached the LIMIT, so the result set was very likely '
                . 'truncated in the middle of a root entity. Fewer than %d root entities were hydrated.',
                $limit,
            );
        }

        return $facts;
    }

    private function createSuggestion(string $entityHint): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/setMaxResults_with_collection_join',
            context: [
                'entity_hint' => $entityHint,
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
