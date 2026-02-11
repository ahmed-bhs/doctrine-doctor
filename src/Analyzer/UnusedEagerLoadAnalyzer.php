<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Webmozart\Assert\Assert;

/**
 * Detects unused eager loading (JOIN FETCH) that wastes memory and bandwidth.
 *
 * Inspired by nplusone's EagerListener which tracks loaded vs accessed instances.
 * This static analyzer detects:
 * - JOINs where the joined table's alias is never used in SELECT/WHERE/ORDER BY
 * - Over-eager loading with multiple JOINs causing data duplication
 * - Redundant eager loading patterns
 *
 * This is a CRITICAL performance issue often overlooked:
 * - Wastes memory by loading unused data
 * - Causes row duplication with collection JOINs
 * - Increases network bandwidth
 * - Slower hydration time
 *
 * Example:
 * ```php
 * // BAD: Loads author but never uses it
 * SELECT a FROM Article a JOIN a.author u
 * foreach ($articles as $article) {
 *     echo $article->getTitle(); // Never calls $article->getAuthor()
 * }
 * ```
 */
class UnusedEagerLoadAnalyzer implements AnalyzerInterface
{
    /** @var int Threshold for detecting over-eager loading (collection JOINs only) */
    private const int MULTIPLE_COLLECTION_JOINS_THRESHOLD = 2;

    /** @var string Issue type identifier */
    private const string ISSUE_TYPE = 'unused_eager_load';

    /**
     * @var array<string, ClassMetadata>|null Cached metadata map
     */
    private ?array $metadataMapCache = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly SqlStructureExtractor $sqlExtractor = new SqlStructureExtractor(),
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                Assert::isIterable($queryDataCollection->toArray(), '$queryDataCollection must be iterable');

                foreach ($queryDataCollection->toArray() as $queryData) {
                    $sql = $queryData->sql;

                    // Only analyze SELECT queries with JOINs
                    if (!$this->sqlExtractor->isSelectQuery($sql) || !$this->sqlExtractor->hasJoin($sql)) {
                        continue;
                    }

                    $issues = $this->detectUnusedEagerLoads($sql, $queryData->backtrace);
                    foreach ($issues as $issueData) {
                        yield $this->issueFactory->create($issueData);
                    }
                }
            },
        );
    }

    /**
     * Detects unused eager loading patterns.
     *
     * @param array<int, array<string, mixed>>|null $backtrace
     *
     * @return array<IssueData>
     */
    private function detectUnusedEagerLoads(string $sql, ?array $backtrace): array
    {
        $issues = [];

        // Pattern 1: Detect JOINs with unused aliases
        $unusedJoins = $this->detectUnusedJoinAliases($sql);
        if (\count($unusedJoins) > 0) {
            $issues[] = $this->createUnusedJoinIssue($sql, $unusedJoins, $backtrace);
        }

        // Pattern 2: Detect over-eager loading (too many JOINs)
        $overEagerIssue = $this->detectOverEagerLoading($sql, $backtrace);
        if (null !== $overEagerIssue) {
            $issues[] = $overEagerIssue;
        }

        return $issues;
    }

    /**
     * Detects JOINs where the joined table's alias is never used.
     *
     * @return array<int, array{type: string, table: string, alias: ?string}>
     */
    private function detectUnusedJoinAliases(string $sql): array
    {
        $joins = $this->sqlExtractor->extractJoins($sql);
        $unusedJoins = [];

        foreach ($joins as $join) {
            $alias = $join['alias'];
            if (null === $alias) {
                continue; // Skip JOINs without alias
            }

            // Build JOIN expression to exclude from alias usage check
            // This prevents counting the alias usage in its own ON clause
            // Note: $alias is guaranteed to be non-null here due to the check above
            $joinExpression = $join['type'] . ' JOIN ' . $join['table'] . ' ' . $alias;

            // Check if alias is used in SELECT, WHERE, ORDER BY, GROUP BY, or HAVING
            // Pass joinExpression so isAliasUsedInQuery ignores this JOIN's ON clause
            $isUsed = $this->sqlExtractor->isAliasUsedInQuery($sql, $alias, $joinExpression);

            if (!$isUsed) {
                $unusedJoins[] = $join;
            }
        }

        return $unusedJoins;
    }

    /**
     * Detects over-eager loading with too many collection JOINs.
     *
     * IMPROVED: Now distinguishes between:
     * - Collection JOINs (OneToMany/ManyToMany) → cause cartesian product
     * - ManyToOne JOINs → no row multiplication
     *
     * Only alerts if >=2 collection JOINs (real performance problem).
     *
     * Examples:
     * - 3 ManyToOne JOINs → OK (1 row per entity)
     * - 2 OneToMany JOINs → PROBLEM (cartesian product!)
     */
    private function detectOverEagerLoading(string $sql, ?array $backtrace): ?IssueData
    {
        $metadataMap = $this->getMetadataMap();

        // Extract FROM table to determine join direction
        $fromTable = $this->extractFromTable($sql, $metadataMap);

        if (null === $fromTable) {
            // Can't analyze without knowing the main table - use higher threshold
            // since we can't determine if these are collection JOINs
            $joinCount = $this->sqlExtractor->countJoins($sql);

            if ($joinCount < 3) {
                return null;
            }

            return $this->createOverEagerIssue($joinCount, $backtrace, isCollection: null);
        }

        // Count collection JOINs specifically (these cause cartesian product)
        $collectionJoinCount = $this->countCollectionJoins($sql, $fromTable, $metadataMap);

        if ($collectionJoinCount < self::MULTIPLE_COLLECTION_JOINS_THRESHOLD) {
            return null; // Not enough collection JOINs to be problematic
        }

        return $this->createOverEagerIssue($collectionJoinCount, $backtrace, isCollection: true);
    }

    /**
     * Create over-eager loading issue.
     */
    private function createOverEagerIssue(int $joinCount, ?array $backtrace, ?bool $isCollection): IssueData
    {
        $joinType = true === $isCollection ? 'collection JOINs (OneToMany/ManyToMany)'
                  : (false === $isCollection ? 'JOINs' : 'JOINs (possibly collections)');

        $description = DescriptionHighlighter::highlight(
            'Over-eager loading detected: {count} {type} in a single query. This can cause significant data duplication and memory waste.',
            [
                'count' => $joinCount,
                'type' => $joinType,
            ],
        );

        if (true === $isCollection) {
            $description .= "\n\n⚠️ Each collection JOIN multiplies the result rows. With {$joinCount} collection JOINs, you may be loading the same parent entity data hundreds or thousands of times.";
            $description .= "\n\nExample: 1 parent × 10 items × 5 comments = 50 SQL rows for 1 entity!";
        } else {
            $description .= "\n\nMultiple JOINs can impact performance and memory usage.";
        }

        $description .= "\n\nConsider using separate queries, batch fetching, or loading only the relations you actually need.";

        return new IssueData(
            type: self::ISSUE_TYPE,
            title: "Over-Eager Loading: {$joinCount} {$joinType} in Single Query",
            description: $description,
            severity: $this->calculateOverEagerSeverity($joinCount, true === $isCollection),
            suggestion: $this->createOverEagerSuggestion($joinCount),
            queries: [],
            backtrace: $backtrace,
        );
    }

    /**
     * @param array<int, array{type: string, table: string, alias: ?string}> $unusedJoins
     * @param array<int, array<string, mixed>>|null                          $backtrace
     */
    private function createUnusedJoinIssue(string $sql, array $unusedJoins, ?array $backtrace): IssueData
    {
        $joinCount = \count($unusedJoins);
        $tables = array_map(fn ($join) => $join['table'], $unusedJoins);
        $aliases = array_map(fn ($join) => $join['alias'] ?? 'unknown', $unusedJoins);

        $description = DescriptionHighlighter::highlight(
            'Unused eager loading detected: {count} JOIN(s) fetching data that is never used. Tables: {tables} (aliases: {aliases})',
            [
                'count' => $joinCount,
                'tables' => implode(', ', $tables),
                'aliases' => implode(', ', $aliases),
            ],
        );

        $description .= "\n\nThese JOINs load data into memory but the joined entities are never accessed in your code.";
        $description .= "\nThis wastes:";
        $description .= "\n- Memory (loading unused entities)";
        $description .= "\n- Bandwidth (transferring unused data)";
        $description .= "\n- CPU (hydrating unused objects)";
        $description .= "\n- Time (larger result sets to process)";

        return new IssueData(
            type: self::ISSUE_TYPE,
            title: "Unused Eager Load: {$joinCount} JOIN(s) Never Accessed",
            description: $description,
            severity: $this->calculateSeverity($joinCount),
            suggestion: $this->createSuggestion($unusedJoins),
            queries: [],
            backtrace: $backtrace,
        );
    }

    /**
     * @param array<int, array{type: string, table: string, alias: ?string}> $unusedJoins
     */
    private function createSuggestion(array $unusedJoins): ?SuggestionInterface
    {
        $tables = array_map(fn ($join) => $join['table'], $unusedJoins);
        $aliases = array_map(fn ($join) => $join['alias'] ?? 'unknown', $unusedJoins);

        return $this->suggestionFactory->createUnusedEagerLoad(
            unusedTables: $tables,
            unusedAliases: $aliases,
        );
    }

    private function createOverEagerSuggestion(int $joinCount): ?SuggestionInterface
    {
        return $this->suggestionFactory->createOverEagerLoading(
            joinCount: $joinCount,
        );
    }

    private function calculateSeverity(int $unusedJoinCount): Severity
    {
        // More unused JOINs = more wasted resources
        if ($unusedJoinCount >= 3) {
            return Severity::critical();
        }

        if ($unusedJoinCount >= 2) {
            return Severity::warning();
        }

        return Severity::info();
    }

    private function calculateOverEagerSeverity(int $joinCount, bool $isCollectionJoin = false): Severity
    {
        // Collection JOINs cause exponential data duplication - more severe
        if ($isCollectionJoin) {
            if ($joinCount >= 3) {
                return Severity::critical(); // 3+ collection JOINs = exponential explosion
            }

            if ($joinCount >= 2) {
                return Severity::warning(); // 2 collection JOINs = significant duplication
            }

            return Severity::info();
        }

        // Regular JOINs (ManyToOne) - less severe
        if ($joinCount >= 5) {
            return Severity::critical();
        }

        if ($joinCount >= 4) {
            return Severity::warning();
        }

        return Severity::info();
    }

    /**
     * Build metadata map (cached for performance).
     * @return array<string, ClassMetadata>
     */
    private function getMetadataMap(): array
    {
        if (null !== $this->metadataMapCache) {
            return $this->metadataMapCache;
        }

        $map = [];
        $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        foreach ($allMetadata as $metadata) {
            $tableName = $metadata->getTableName();
            $map[$tableName] = $metadata;
        }

        $this->metadataMapCache = $map;

        return $map;
    }

    /**
     * Extract FROM table from SQL.
     * @param array<string, ClassMetadata> $metadataMap
     */
    private function extractFromTable(string $sql, array $metadataMap): ?string
    {
        $mainTable = $this->sqlExtractor->extractMainTable($sql);

        if (null === $mainTable) {
            return null;
        }

        $tableName = $mainTable['table'];

        if (!isset($metadataMap[$tableName])) {
            return null;
        }

        return $tableName;
    }

    /**
     * Count collection JOINs (OneToMany/ManyToMany) in the query.
     * @param array<string, ClassMetadata> $metadataMap
     */
    private function countCollectionJoins(string $sql, string $fromTable, array $metadataMap): int
    {
        $joins = $this->sqlExtractor->extractJoins($sql);
        $collectionCount = 0;

        foreach ($joins as $join) {
            if ($this->isCollectionJoin($join, $metadataMap, $sql, $fromTable)) {
                ++$collectionCount;
            }
        }

        return $collectionCount;
    }

    /**
     * Determine if a JOIN is on a collection (OneToMany/ManyToMany).
     * Reuses logic from JoinOptimizationAnalyzer.
     *
     * @param array<string, mixed> $join
     * @param array<string, ClassMetadata> $metadataMap
     */
    private function isCollectionJoin(array $join, array $metadataMap, string $sql, string $fromTable): bool
    {
        $joinTable = $join['table'];

        if (!is_string($joinTable)) {
            return false;
        }

        $metadata = $metadataMap[$joinTable] ?? null;

        if (null === $metadata) {
            return false;
        }

        return $this->isForeignKeyInJoinedTable($sql, $fromTable, $joinTable, $metadataMap);
    }

    /**
     * Determine if FK is in joined table (making it a collection).
     * Simplified version from JoinOptimizationAnalyzer.
     *
     * @param array<string, ClassMetadata> $metadataMap
     */
    private function isForeignKeyInJoinedTable(string $sql, string $fromTable, string $joinTable, array $metadataMap): bool
    {
        $fromMetadata = $metadataMap[$fromTable] ?? null;
        $joinMetadata = $metadataMap[$joinTable] ?? null;

        if (null === $fromMetadata || null === $joinMetadata) {
            return false;
        }

        $fromPKs = $fromMetadata->getIdentifierFieldNames();
        $joinPKs = $joinMetadata->getIdentifierFieldNames();

        $conditions = $this->sqlExtractor->extractJoinOnConditions($sql, $joinTable);

        if ([] === $conditions) {
            return $this->canBeCollection($joinTable, $metadataMap);
        }

        $condition = $conditions[0];

        $leftParts = explode('.', $condition['left']);
        $rightParts = explode('.', $condition['right']);

        $leftCol = end($leftParts);
        $rightCol = end($rightParts);

        // from.PK = join.nonPK → Collection
        if (in_array($leftCol, $fromPKs, true) && !in_array($rightCol, $joinPKs, true)) {
            return true;
        }

        // from.nonPK = join.PK → NOT collection
        if (!in_array($leftCol, $fromPKs, true) && in_array($rightCol, $joinPKs, true)) {
            return false;
        }

        // Uncertain - check metadata
        return $this->canBeCollection($joinTable, $metadataMap);
    }

    /**
     * Fallback: Check if table CAN be a collection based on metadata.
     * @param array<string, ClassMetadata> $metadataMap
     */
    private function canBeCollection(string $tableName, array $metadataMap): bool
    {
        foreach ($metadataMap as $metadata) {
            foreach ($metadata->getAssociationMappings() as $mapping) {
                $targetEntity = $mapping['targetEntity'] ?? null;

                if (null === $targetEntity) {
                    continue;
                }

                try {
                    $targetMetadata = $this->entityManager->getClassMetadata($targetEntity);

                    if ($targetMetadata->getTableName() === $tableName) {
                        if (
                            ClassMetadata::ONE_TO_MANY === $mapping['type']
                            || ClassMetadata::MANY_TO_MANY === $mapping['type']
                        ) {
                            return true;
                        }
                    }
                } catch (\Exception) {
                    continue;
                }
            }
        }

        return false;
    }
}
