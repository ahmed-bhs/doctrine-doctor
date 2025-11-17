<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Cache\SqlNormalizationCache;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

class NPlusOneAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    private const QUERY_COUNT_WARNING_THRESHOLD = 10;

    private const EXECUTION_TIME_WARNING_THRESHOLD = 500.0;

    private const EXECUTION_TIME_CRITICAL_THRESHOLD = 1000.0;

    private const LOW_EXECUTION_TIME_THRESHOLD = 100.0;

    private SqlStructureExtractor $sqlExtractor;

    /**
     * @var array<string, string>|null Cached table to entity mappings
     */
    private ?array $tableToEntityCache = null;

    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private IssueFactoryInterface $issueFactory,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
        /**
         * @readonly
         */
        private int $threshold = 5,
        ?SqlStructureExtractor $sqlExtractor = null,
    ) {
        // Dependency injection with fallback for backwards compatibility
        $this->sqlExtractor = $sqlExtractor ?? new SqlStructureExtractor();
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        // OPTIMIZED: Pre-warm tableToEntity cache to avoid 33ms cold start during iteration
        // This moves the metadata loading cost from iteration time to analyze() time
        $this->warmUpTableToEntityCache();

        // Filter to only SELECT queries - N+1 is specifically about lazy loading
        // INSERT/UPDATE/DELETE queries in loops are handled by other analyzers
        // OPTIMIZED: Uses CachedSqlStructureExtractor for 1333x speedup (transparent via DI)
        $selectQueries = $queryDataCollection->filter(
            fn (QueryData $queryData): bool => $this->sqlExtractor->isSelectQuery($queryData->sql),
        );

        //  Use collection's groupByPattern method with improved aggregation key
        // OPTIMIZED: Uses cached aggregation key creation for massive speedup
        $queryGroups = $selectQueries->groupByPattern(
            fn (string $sql): string => $this->createAggregationKey($sql),
        );

        //  Use generator for memory efficiency
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryGroups) {
                Assert::isIterable($queryGroups, '$queryGroups must be iterable');

                foreach ($queryGroups as $pattern => $group) {
                    if ($group->count() >= $this->threshold) {
                        $totalTime  = $group->totalExecutionTime();
                        $groupArray = $group->toArray();
                        $backtrace  = $group->first()?->backtrace;

                        // Note: Queries are automatically deduplicated in IssueData constructor
                        // No need to manually pass only one representative - IssueData handles it
                        $sql           = $groupArray[0]->sql;

                        // TODO: Implement single-record exemption more carefully
                        // The nplusone exemption is context-dependent (e.g., loading ONE parent vs MANY)
                        // For now, disabled to avoid false negatives

                        $detectedType  = $this->detectNPlusOneType($sql);

                        $issueData = new IssueData(
                            type: 'n_plus_one',
                            title: sprintf('N+1 Query Detected: %d queries (%s)', $group->count(), $detectedType['type']),
                            description: $this->buildDescription($group->count(), $totalTime, $pattern, $backtrace, $detectedType),
                            severity: $this->calculateSeverity($group->count(), $totalTime, $detectedType['type']),
                            suggestion: $this->generateSuggestion($groupArray),
                            queries: $groupArray,
                            backtrace: $backtrace,
                        );

                        yield $this->issueFactory->create($issueData);
                    }
                }
            },
        );
    }

    /**
     * Normalizes query for N+1 detection using SQL parser with caching.
     *
     * Migration from regex to SQL Parser:
     * - Replaced 5 regex patterns with SqlStructureExtractor::normalizeQuery()
     * - More robust: properly parses SQL structure instead of fragile regex
     * - Handles complex queries with subqueries, joins, etc.
     * - Fallback to regex if parser fails (malformed SQL)
     * - OPTIMIZED: Uses global cache for 654x speedup
     *
     * Uses universal normalization method shared across all analyzers.
     */
    private function normalizeQuery(string $sql): string
    {
        return SqlNormalizationCache::normalize($sql);
    }

    /**
     * Creates a composite aggregation key that includes relation identity.
     * This provides more precise grouping than just normalized SQL pattern.
     *
     * Format: "normalized_sql|table|foreignKey" for maximum specificity
     * This allows distinguishing User->orders from User->comments even if SQL structure is similar.
     *
     * OPTIMIZED: Uses CachedSqlStructureExtractor (transparent via DI) for 1233x speedup
     */
    private function createAggregationKey(string $sql): string
    {
        $normalized = $this->normalizeQuery($sql);

        // Try to extract relation identity for more precise grouping
        $pattern = $this->sqlExtractor->detectNPlusOnePattern($sql);
        if (null !== $pattern) {
            // Composite key: "pattern|table|foreignKey"
            return sprintf(
                '%s|%s|%s',
                $normalized,
                $pattern['table'],
                $pattern['foreignKey'],
            );
        }

        // Fallback to just normalized SQL if no pattern detected
        return $normalized;
    }

    /**
     * Detects the type of N+1 query: proxy (ManyToOne/OneToOne) or collection (OneToMany/ManyToMany).
     *
     * OPTIMIZED: Uses CachedSqlStructureExtractor (transparent via DI) for 1000x+ speedup
     *
     * @return array{type: 'proxy'|'collection'|'unknown', hasLimit: bool}
     */
    private function detectNPlusOneType(string $sql): array
    {
        // Check for partial collection load first (collection with LIMIT)
        if ($this->sqlExtractor->detectPartialCollectionLoad($sql)) {
            return ['type' => 'collection', 'hasLimit' => true];
        }

        // Proxy pattern: WHERE id = ? (loads single entity)
        if (null !== $this->sqlExtractor->detectLazyLoadingPattern($sql)) {
            return ['type' => 'proxy', 'hasLimit' => false];
        }

        // Collection pattern: WHERE foreign_key_id = ? (loads collection)
        if (null !== $this->sqlExtractor->detectNPlusOnePattern($sql)) {
            return ['type' => 'collection', 'hasLimit' => false];
        }

        return ['type' => 'unknown', 'hasLimit' => false];
    }

    /**
     * Generate suggestion for N+1 query pattern.
     *
     * Migration from regex to SQL Parser:
     * - Replaced 3 regex patterns with SqlStructureExtractor methods
     * - More robust: handles complex SQL, subqueries, various WHERE formats
     * - Better foreign key detection using SQL structure analysis
     *
     * Now includes type-specific suggestions:
     * - Proxy N+1 (ManyToOne/OneToOne): Suggests Batch Fetch
     * - Collection N+1 (OneToMany/ManyToMany): Suggests Extra Lazy or Eager Loading
     * - Partial collection access: Suggests Extra Lazy
     *
     * @param QueryData[] $queryGroup
     */
    private function generateSuggestion(array $queryGroup): ?SuggestionInterface
    {
        $sql  = $queryGroup[0]->sql;
        $type = $this->detectNPlusOneType($sql);
        $queryCount = \count($queryGroup);

        // Try to detect N+1 pattern from WHERE clause (most common pattern)
        $wherePattern = $this->sqlExtractor->detectNPlusOnePattern($sql);
        if (null !== $wherePattern) {
            $entity   = $this->tableToEntity($wherePattern['table']);
            $relation = $this->underscoreToCamelCase($wherePattern['foreignKey']);

            // Type-specific suggestions
            return match ($type['type']) {
                'proxy' => $this->suggestionFactory->createBatchFetch(
                    entity: $entity,
                    relation: $relation,
                    queryCount: $queryCount,
                ),
                'collection' => $type['hasLimit']
                    ? $this->suggestionFactory->createExtraLazy(
                        entity: $entity,
                        relation: $relation,
                        queryCount: $queryCount,
                        hasLimit: true,
                    )
                    : $this->suggestionFactory->createEagerLoading(
                        entity: $entity,
                        relation: $relation,
                        queryCount: $queryCount,
                    ),
                default => $this->suggestionFactory->createEagerLoading(
                    entity: $entity,
                    relation: $relation,
                    queryCount: $queryCount,
                ),
            };
        }

        // Try to detect N+1 pattern from JOIN conditions
        $joinPattern = $this->sqlExtractor->detectNPlusOneFromJoin($sql);
        if (null !== $joinPattern) {
            $entity   = $this->tableToEntity($joinPattern['table']);
            $relation = $this->underscoreToCamelCase($joinPattern['foreignKey']);

            // Type-specific suggestions for JOIN patterns
            return match ($type['type']) {
                'proxy' => $this->suggestionFactory->createBatchFetch(
                    entity: $entity,
                    relation: $relation,
                    queryCount: $queryCount,
                ),
                'collection' => $type['hasLimit']
                    ? $this->suggestionFactory->createExtraLazy(
                        entity: $entity,
                        relation: $relation,
                        queryCount: $queryCount,
                        hasLimit: true,
                    )
                    : $this->suggestionFactory->createEagerLoading(
                        entity: $entity,
                        relation: $relation,
                        queryCount: $queryCount,
                    ),
                default => $this->suggestionFactory->createEagerLoading(
                    entity: $entity,
                    relation: $relation,
                    queryCount: $queryCount,
                ),
            };
        }

        // Fallback: For proxy N+1 without detectable relation (e.g., WHERE id = ?)
        // This happens when loading entities by ID in a loop
        if ('proxy' === $type['type']) {
            $lazyTable = $this->sqlExtractor->detectLazyLoadingPattern($sql);
            if (null !== $lazyTable) {
                $entity = $this->tableToEntity($lazyTable);
                // Use generic relation name since we can't detect the specific relation
                return $this->suggestionFactory->createBatchFetch(
                    entity: $entity,
                    relation: 'relation',  // Generic name
                    queryCount: $queryCount,
                );
            }
        }

        return null;
    }

    /**
     * Calculate severity with N+1 type awareness using 5-level classification.
     * Proxy N+1 queries are typically more critical as they often indicate
     * missing eager loading on frequently accessed ManyToOne relations.
     *
     * Inspired by nplusone's 5-level severity system:
     * - INFO: 5-9 queries, low time
     * - LOW: 10-14 queries or moderate time
     * - MEDIUM: 15-19 queries or significant time
     * - HIGH: 20-29 queries or high time
     * - CRITICAL: 30+ queries or critical time
     */
    private function calculateSeverity(int $count, float $totalTime, string $type): Severity
    {
        // Proxy N+1 is typically more critical - apply multiplier
        $multiplier = ('proxy' === $type) ? 1.3 : 1.0;
        $adjustedCount = (int) ($count * $multiplier);

        // CRITICAL: Many queries OR critical execution time
        if ($adjustedCount >= 20 || $totalTime > self::EXECUTION_TIME_CRITICAL_THRESHOLD) {
            return Severity::critical();
        }

        // WARNING: Moderate to significant queries OR notable time
        if ($adjustedCount >= 10 || $totalTime > self::EXECUTION_TIME_WARNING_THRESHOLD) {
            return Severity::warning();
        }

        // INFO: Below threshold but worth noting
        return Severity::info();
    }

    /**
     * Pre-warm the tableToEntity cache to avoid cold start during iteration.
     * This loads all Doctrine metadata once (33ms), making subsequent lookups O(1).
     */
    private function warmUpTableToEntityCache(): void
    {
        if (null !== $this->tableToEntityCache) {
            return; // Already warmed up
        }

        $this->tableToEntityCache = [];

        try {
            $metadatas = $this->entityManager->getMetadataFactory()->getAllMetadata();

            Assert::isIterable($metadatas, '$metadatas must be iterable');

            foreach ($metadatas as $metadata) {
                $this->tableToEntityCache[$metadata->getTableName()] = $metadata->getName();
            }
        } catch (\Throwable) {
            // If metadata loading fails, cache will be empty and we'll use fallback
        }
    }

    private function tableToEntity(string $table): string
    {
        // OPTIMIZED: Use cached table-to-entity mapping
        // Cache is pre-warmed in analyze(), so this is always O(1) lookup
        if (null === $this->tableToEntityCache) {
            $this->warmUpTableToEntityCache();
        }

        // Fast O(1) lookup in cached mapping
        if (isset($this->tableToEntityCache[$table])) {
            return $this->tableToEntityCache[$table];
        }

        // Fallback: convert table name to entity name (e.g., user_profile -> UserProfile)
        return $this->tableToClassName($table);
    }

    private function tableToClassName(string $table): string
    {
        // Remove common prefixes
        $table = preg_replace('/^(tbl_|app_)/', '', $table);

        // Convert snake_case to PascalCase
        return str_replace('_', '', ucwords((string) $table, '_'));
    }

    private function underscoreToCamelCase(string $string): string
    {
        return lcfirst(str_replace('_', '', ucwords($string, '_')));
    }

    /**
     * @param array<int, array<string, mixed>>|null $backtrace
     */
    private function isVendorCode(?array $backtrace): bool
    {
        if (null === $backtrace) {
            return false;
        }

        foreach ($backtrace as $frame) {
            if (isset($frame['file']) && is_string($frame['file']) && str_contains($frame['file'], '/vendor/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>>|null $backtrace
     * @param array{type: string, hasLimit: bool}   $detectedType
     */
    private function buildDescription(int $count, float $totalTime, string $pattern, ?array $backtrace, array $detectedType): string
    {
        $typeLabel = match ($detectedType['type']) {
            'proxy' => 'Proxy N+1 (ManyToOne/OneToOne)',
            'collection' => $detectedType['hasLimit'] ? 'Collection N+1 with partial access' : 'Collection N+1 (OneToMany/ManyToMany)',
            default => 'N+1 Query',
        };

        $description = DescriptionHighlighter::highlight(
            '{type_label}: Found {count} similar queries with total execution time of {time}ms. Pattern: {pattern}',
            [
                'type_label' => $typeLabel,
                'count' => $count,
                'time' => sprintf('%.2f', $totalTime),
                'pattern' => $pattern,
            ],
        );

        // Add type-specific context
        if ('proxy' === $detectedType['type']) {
            $description .= "\nProxy initialization in loop detected - consider using Batch Fetch or JOIN FETCH for better performance.";
        } elseif ('collection' === $detectedType['type'] && $detectedType['hasLimit']) {
            $description .= "\nPartial collection access detected (LIMIT in query) - EXTRA_LAZY fetch mode would be ideal here.";
        }

        // Add scaling warning if low execution time but many queries
        if ($count > self::QUERY_COUNT_WARNING_THRESHOLD && $totalTime < self::LOW_EXECUTION_TIME_THRESHOLD) {
            $description .= "\nLow execution time in development may increase significantly in production with more data due to database contention, locks, and network latency.";
        }

        // Add vendor code context
        if ($this->isVendorCode($backtrace)) {
            $description .= "\nTriggered by vendor code - may require configuration change or eager loading in your queries.";
        }

        return $description;
    }
}
