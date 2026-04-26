<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
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
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

/**
 * Detects queries that could benefit from result caching.
 * This analyzer identifies two main caching opportunities:
 * 1. Frequent queries - queries executed multiple times in the same request
 * 2. Static table queries - queries on rarely-changing lookup tables
 * Query caching can dramatically reduce database load by reusing results
 * for identical queries or slow-changing data.
 * Example opportunities:
 * - Query executed 10+ times → should use result cache
 * - Query on 'countries' table → can cache for 24h
 * - Same product lookup repeated → cache with product ID as key
 */
class QueryCachingOpportunityAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    private const array DEFAULT_STATIC_TABLES = [
        'countries',
        'currencies',
        'languages',
        'locales',
        'timezones',
        'settings',
        'config',
        'configuration',
        'permissions',
        'roles',
        'statuses',
        'categories',
        'tags',
    ];

    private const int DEFAULT_FREQUENCY_THRESHOLD_WARNING = 5;

    private const int DEFAULT_FREQUENCY_THRESHOLD_CRITICAL = 10;

    private const int DEFAULT_SECOND_LEVEL_CACHE_THRESHOLD = 5;

    private const float DEFAULT_SECOND_LEVEL_CACHE_MAX_AVG_MS = 2.0;

    public function __construct(
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly array $staticTables = self::DEFAULT_STATIC_TABLES,
        private readonly int $frequencyThreshold = 3,
        private readonly int $secondLevelCacheThreshold = self::DEFAULT_SECOND_LEVEL_CACHE_THRESHOLD,
        private readonly float $secondLevelCacheMaxAvgMs = self::DEFAULT_SECOND_LEVEL_CACHE_MAX_AVG_MS,
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
                [$queryFrequencies, $queryDetails] = $this->collectQueryFrequencies($queryDataCollection);

                yield from $this->generateFrequentQueryIssues($queryFrequencies, $queryDetails);
                yield from $this->generateSecondLevelCacheIssues($queryDataCollection);

                yield from $this->generateStaticTableIssues($queryDataCollection);
            },
        );
    }

    public function getName(): string
    {
        return 'Query Caching Opportunity Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects queries that could benefit from result caching to reduce database load';
    }

    /**
     * Collect query frequencies and details.
     * @return array{0: array<string, int>, 1: array<string, array{originalSql: string, totalTime: float, backtrace: ?array, queries: array}>}
     * @phpstan-return array{0: array<string, int>, 1: array<string, array{originalSql: string, totalTime: float, backtrace: ?array, queries: array}>}
     */
    private function collectQueryFrequencies(QueryDataCollection $queryDataCollection): array
    {
        /** @var array<string, int> */
        $queryFrequencies = [];
        /** @var array<string, array{originalSql: string, totalTime: float, backtrace: ?array, queries: array, uniqueParamSets: array<string, true>}> */
        $queryDetails = [];

        foreach ($queryDataCollection as $query) {
            $sql = $this->extractSQL($query);
            $executionTime = $this->extractExecutionTime($query);
            $params = $this->extractParams($query);

            if ('' === $sql || '0' === $sql) {
                continue;
            }

            $normalized = $this->normalizeQuery($sql);

            $queryKey = $this->createQueryKey($sql, $normalized, $params);

            if (!isset($queryFrequencies[$queryKey])) {
                $queryFrequencies[$queryKey] = 0;
                $queryDetails[$queryKey] = [
                    'originalSql' => $sql,
                    'totalTime' => 0.0,
                    'backtrace' => $this->extractBacktrace($query),
                    'queries' => [],
                    'uniqueParamSets' => [],
                ];
            }

            $queryFrequencies[$queryKey]++;
            $existingTotal = $queryDetails[$queryKey]['totalTime'];
            $queryDetails[$queryKey]['totalTime'] = $existingTotal + $executionTime;
            $queryDetails[$queryKey]['queries'][] = $query;

            $paramsHash = md5(json_encode($params, JSON_THROW_ON_ERROR));
            $queryDetails[$queryKey]['uniqueParamSets'][$paramsHash] = true;
        }

        /** @phpstan-ignore-next-line */
        return [$queryFrequencies, $queryDetails];
    }

    /**
     * Generate issues for frequently executed queries.
     * @param array<string, int> $queryFrequencies
     * @param array<string, array{originalSql: string, totalTime: float, backtrace: ?array, queries: array, uniqueParamSets: array<string, true>}> $queryDetails
     * @return \Generator<PerformanceIssue>
     */
    private function generateFrequentQueryIssues(array $queryFrequencies, array $queryDetails): \Generator
    {
        foreach ($queryFrequencies as $normalized => $count) {
            if ($count < $this->frequencyThreshold) {
                continue;
            }

            $details = $queryDetails[$normalized];

            if (count($details['uniqueParamSets']) > 1) {
                continue;
            }

            if ($this->isSelectQuery($details['originalSql'])) {
                yield $this->createFrequentQueryIssue(
                    $details['originalSql'],
                    $count,
                    $details['totalTime'],
                    $details['backtrace'],
                    $details['queries'],
                );
            }
        }
    }

    /**
     * Generate Doctrine second-level cache opportunities.
     * Detects many fast SELECT queries with varying parameter sets,
     * which often indicates repeated entity loads that 2LC can optimize.
     *
     * @return \Generator<PerformanceIssue>
     */
    private function generateSecondLevelCacheIssues(QueryDataCollection $queryDataCollection): \Generator
    {
        /** @var array<string, array{originalSql: string, count: int, totalTime: float, backtrace: ?array, queries: array, uniqueParamSets: array<string, true>}> $groups */
        $groups = [];

        foreach ($queryDataCollection as $query) {
            $sql = $this->extractSQL($query);

            if ('' === $sql || '0' === $sql || !$this->isSelectQuery($sql)) {
                continue;
            }

            $normalized = $this->normalizeQuery($sql);
            $executionTime = $this->extractExecutionTime($query);
            $params = $this->extractParams($query);

            if (!isset($groups[$normalized])) {
                $groups[$normalized] = [
                    'originalSql' => $sql,
                    'count' => 0,
                    'totalTime' => 0.0,
                    'backtrace' => $this->extractBacktrace($query),
                    'queries' => [],
                    'uniqueParamSets' => [],
                ];
            }

            $groups[$normalized]['count']++;
            $groups[$normalized]['totalTime'] += $executionTime;
            $groups[$normalized]['queries'][] = $query;
            $paramsHash = md5(json_encode($params, JSON_THROW_ON_ERROR));
            $groups[$normalized]['uniqueParamSets'][$paramsHash] = true;
        }

        foreach ($groups as $group) {
            $count = $group['count'];

            if ($count < $this->secondLevelCacheThreshold) {
                continue;
            }

            if (count($group['uniqueParamSets']) < 2) {
                continue;
            }

            $avgTime = $group['totalTime'] / max(1, $count);

            if ($avgTime > $this->secondLevelCacheMaxAvgMs) {
                continue;
            }

            $sql = $group['originalSql'];

            if ($this->isComplexQueryForSecondLevelCache($sql)) {
                continue;
            }

            yield $this->createSecondLevelCacheIssue(
                $sql,
                $count,
                $avgTime,
                $group['backtrace'],
                $group['queries'],
            );
        }
    }

    /**
     * Generate issues for queries on static tables.
     * @return \Generator<PerformanceIssue>
     */
    private function generateStaticTableIssues(QueryDataCollection $queryDataCollection): \Generator
    {
        $reportedStaticTables = [];

        foreach ($queryDataCollection as $query) {
            $sql = $this->extractSQL($query);

            if ('' === $sql || '0' === $sql) {
                continue;
            }

            if (!$this->isSelectQuery($sql)) {
                continue;
            }

            $staticTable = $this->getStaticTableFromQuery($sql);
            if (null !== $staticTable && !isset($reportedStaticTables[$staticTable])) {
                $reportedStaticTables[$staticTable] = true;

                yield $this->createStaticTableCachingIssue(
                    $sql,
                    $staticTable,
                    $this->extractBacktrace($query),
                    $query,
                );
            }
        }
    }

    /**
     * Extract SQL from query data.
     */
    private function extractSQL(array|object $query): string
    {
        if (is_array($query)) {
            return $query['sql'] ?? '';
        }

        return is_object($query) && property_exists($query, 'sql') ? $query->sql : '';
    }

    /**
     * Extract execution time from query data.
     */
    private function extractExecutionTime(array|object $query): float
    {
        if (is_array($query)) {
            return (float) ($query['executionMS'] ?? 0);
        }

        if (is_object($query) && property_exists($query, 'executionTime')) {
            return $query->executionTime?->inMilliseconds() ?? 0.0;
        }

        return 0.0;
    }

    /**
     * Extract backtrace from query data.
     * @return array<int, array<string, mixed>>|null
     */
    private function extractBacktrace(array|object $query): ?array
    {
        if (is_array($query)) {
            return $query['backtrace'] ?? null;
        }

        if (is_object($query) && property_exists($query, 'backtrace')) {
            return $query->backtrace ?? null;
        }

        return null;
    }

    /**
     * Extract parameters from query data.
     * @return array<mixed>
     */
    private function extractParams(array|object $query): array
    {
        if (is_array($query)) {
            $params = $query['params'] ?? [];
            return is_array($params) ? $params : [];
        }

        if (is_object($query) && property_exists($query, 'params')) {
            $params = $query->params ?? [];
            return is_array($params) ? $params : [];
        }

        return [];
    }

    /**
     * Create a unique key for query identification.
     * Combines normalized SQL with serialized parameters to detect TRUE duplicates.
     *
     * @param array<mixed> $params
     */
    private function createQueryKey(string $originalSql, string $normalizedSql, array $params): string
    {
        $upperSql = strtoupper($originalSql);
        if (str_contains($upperSql, 'LIMIT') || str_contains($upperSql, 'OFFSET')) {
            return md5($originalSql);
        }

        if (empty($params)) {
            return $normalizedSql;
        }

        $paramsHash = md5(json_encode($params, JSON_THROW_ON_ERROR));

        return $normalizedSql . '::' . $paramsHash;
    }

    /**
     * Normalize a query by replacing values with placeholders.
     * This allows detecting structurally identical queries.
     */
    /**
     * Normalizes query using universal SQL parser method.
     *
     * Migration from regex to SQL Parser:
     * - Replaced 5 regex patterns with SqlStructureExtractor::normalizeQuery()
     * - More robust: properly parses SQL structure
     * - Handles complex queries, subqueries, joins
     * - Handles IN clauses automatically
     * - Fallback to regex if parser fails
     */
    private function normalizeQuery(string $sql): string
    {
        return SqlNormalizationCache::normalize($sql);
    }

    /**
     * Check if query is a SELECT query.
     * Only SELECT queries can benefit from result caching.
     * INSERT, UPDATE, DELETE queries modify data and cannot be cached.
     */
    private function isSelectQuery(string $sql): bool
    {
        $trimmed = ltrim($sql);
        return 0 === stripos($trimmed, 'SELECT');
    }

    /**
     * Check if query is on a static table.
     * Returns table name if static, null otherwise.
     *
     * Migration from regex to SQL Parser:
     * - Replaced 2 regex patterns (FROM/JOIN detection) with SqlStructureExtractor
     * - More robust: handles subqueries, database prefixes, complex SQL
     * - Avoids false positives from table names in comments or strings
     */
    private function getStaticTableFromQuery(string $sql): ?string
    {
        $tableNames = $this->sqlExtractor->getAllTableNames($sql);

        foreach ($this->staticTables as $staticTable) {
            if (in_array(strtolower($staticTable), $tableNames, true)) {
                return $staticTable;
            }
        }

        return null;
    }

    /**
     * Heuristic guardrail:
     * 2LC fits simple repeated entity-load style SELECTs better than
     * complex analytical/multi-join queries.
     */
    private function isComplexQueryForSecondLevelCache(string $sql): bool
    {
        $normalized = strtolower(SqlNormalizationCache::normalize($sql));

        return str_contains($normalized, ' join ')
            || str_contains($normalized, ' group by ')
            || str_contains($normalized, ' having ')
            || str_contains($normalized, ' union ')
            || str_contains($normalized, ' in (?)');
    }

    private function determineFrequencySeverity(int $count): Severity
    {
        $warningThreshold = max(self::DEFAULT_FREQUENCY_THRESHOLD_WARNING, $this->frequencyThreshold + 2);
        $criticalThreshold = max(self::DEFAULT_FREQUENCY_THRESHOLD_CRITICAL, $this->frequencyThreshold * 2);

        return match (true) {
            $count >= $criticalThreshold => Severity::critical(),
            $count >= $warningThreshold => Severity::warning(),
            default => Severity::info(),
        };
    }

    private function createFrequentQueryIssue(
        string $originalSql,
        int $count,
        float $totalTime,
        ?array $backtrace,
        array $queries,
    ): PerformanceIssue {
        $severity = $this->determineFrequencySeverity($count);

        $avgTime = $totalTime / $count;
        $cacheAccessTime = $avgTime / 100; // Cache is ~100x faster
        $timeWithCache = $avgTime + ($cacheAccessTime * ($count - 1));
        $timeSaved = $totalTime - $timeWithCache;
        $improvementPercent = ($timeSaved / $totalTime) * 100;

        $issueData = new IssueData(
            type: 'frequent_query_without_cache',
            title: sprintf('Frequent Query Executed %d Times', $count),
            description: sprintf(
                "Query executed %d times in this request, consuming %.2fms total. " .
                "Using result cache with useResultCache() would save ~%.2fms (%d%% faster) by avoiding %d redundant database hits.",
                $count,
                $totalTime,
                $timeSaved,
                (int) round($improvementPercent),
                $count - 1,
            ),
            severity: $severity,
            suggestion: $this->createFrequentQuerySuggestion($originalSql, $count, $totalTime),
            queries: $queries,
            backtrace: $backtrace,
        );

        return new PerformanceIssue($issueData->toArray());
    }

    /**
     * Create issue for query on static table.
     */
    private function createStaticTableCachingIssue(
        string $sql,
        string $tableName,
        ?array $backtrace,
        array|object $query,
    ): PerformanceIssue {
        $issueData = new IssueData(
            type: 'static_table_caching_opportunity',
            title: sprintf("Query on Static Table '%s'", $tableName),
            description: sprintf(
                "Query accesses '%s' table, which typically contains static/rarely-changing data. " .
                "Consider caching results for 1-24 hours to reduce database load. " .
                "Static lookup tables are excellent caching candidates.",
                $tableName,
            ),
            severity: Severity::info(),
            suggestion: $this->createStaticTableSuggestion($sql, $tableName),
            queries: [$query], // @phpstan-ignore argument.type (query is QueryData from collection)
            backtrace: $backtrace,
        );

        return new PerformanceIssue($issueData->toArray());
    }

    private function createSecondLevelCacheIssue(
        string $sql,
        int $count,
        float $avgTime,
        ?array $backtrace,
        array $queries,
    ): PerformanceIssue {
        $issueData = new IssueData(
            type: 'doctrine_2lc_opportunity',
            title: sprintf('Doctrine 2LC Opportunity (%d fast entity loads)', $count),
            description: sprintf(
                "Detected %d fast SELECT queries (avg %.2fms) with different parameter sets. " .
                "This pattern suggests repeated entity loading that can benefit from Doctrine second-level cache.",
                $count,
                $avgTime,
            ),
            severity: Severity::warning(),
            suggestion: $this->createSecondLevelCacheSuggestion($sql, $count, $avgTime),
            queries: $queries,
            backtrace: $backtrace,
        );

        return new PerformanceIssue($issueData->toArray());
    }

    /**
     * Create suggestion for frequent query caching.
     */
    private function createFrequentQuerySuggestion(
        string $sql,
        int $count,
        float $totalTime,
    ): mixed {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/query_caching_frequent',
            context: [
                'sql' => $sql,
                'count' => $count,
                'total_time' => $totalTime,
                'avg_time' => $totalTime / $count,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $this->determineFrequencySeverity($count),
                title: 'Enable result cache for frequent query',
                tags: ['performance', 'cache', 'optimization'],
            ),
        );
    }

    /**
     * Create suggestion for static table caching.
     */
    private function createStaticTableSuggestion(
        string $sql,
        string $tableName,
    ): mixed {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/query_caching_static',
            context: [
                'sql' => $sql,
                'table_name' => $tableName,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::info(),
                title: 'Cache static table query results',
                tags: ['performance', 'cache', 'static-data'],
            ),
        );
    }

    private function createSecondLevelCacheSuggestion(
        string $sql,
        int $count,
        float $avgTime,
    ): mixed {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/query_caching_2lc',
            context: [
                'sql' => $sql,
                'count' => $count,
                'avg_time' => $avgTime,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: 'Enable Doctrine second-level cache for repeated entity loads',
                tags: ['performance', 'cache', 'doctrine-2lc'],
            ),
        );
    }
}
