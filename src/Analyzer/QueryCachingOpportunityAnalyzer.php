<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
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
class QueryCachingOpportunityAnalyzer implements AnalyzerInterface
{
    /**
     * Tables that are typically static/rarely change.
     * These are good candidates for long-term caching.
     */
    private const STATIC_TABLES = [
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

    /**
     * Minimum occurrences to suggest caching.
     */
    private const FREQUENCY_THRESHOLD_INFO = 3;

    private const FREQUENCY_THRESHOLD_WARNING = 5;

    private const FREQUENCY_THRESHOLD_CRITICAL = 10;

    public function __construct(
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                // First pass: collect and normalize queries
                [$queryFrequencies, $queryDetails] = $this->collectQueryFrequencies($queryDataCollection);

                // Second pass: generate issues for frequent queries
                yield from $this->generateFrequentQueryIssues($queryFrequencies, $queryDetails);

                // Third pass: detect static table queries (only report once per table)
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
     * @return array{0: array<string, int>, 1: array<string, array{originalSql: string, totalTime: float, backtrace: ?array}>}
     * @phpstan-return array{0: array<string, int>, 1: array<string, array{originalSql: string, totalTime: float, backtrace: ?array}>}
     */
    private function collectQueryFrequencies(QueryDataCollection $queryDataCollection): array
    {
        /** @var array<string, int> */
        $queryFrequencies = [];
        /** @var array<string, array{originalSql: string, totalTime: float, backtrace: ?array}> */
        $queryDetails = [];

        assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

        foreach ($queryDataCollection as $query) {
            $sql = $this->extractSQL($query);
            $executionTime = $this->extractExecutionTime($query);

            if ('' === $sql || '0' === $sql) {
                continue;
            }

            // Normalize query (replace values with placeholders)
            $normalized = $this->normalizeQuery($sql);

            if (!isset($queryFrequencies[$normalized])) {
                $queryFrequencies[$normalized] = 0;
                $queryDetails[$normalized] = [
                    'originalSql' => $sql,
                    'totalTime' => 0.0,
                    'backtrace' => $this->extractBacktrace($query),
                ];
            }

            $queryFrequencies[$normalized]++;
            $existingTotal = $queryDetails[$normalized]['totalTime'];
            $queryDetails[$normalized]['totalTime'] = $existingTotal + $executionTime;
        }

        /** @phpstan-ignore-next-line */
        return [$queryFrequencies, $queryDetails];
    }

    /**
     * Generate issues for frequently executed queries.
     * @param array<string, int> $queryFrequencies
     * @param array<string, array{originalSql: string, totalTime: float, backtrace: ?array}> $queryDetails
     * @return \Generator<PerformanceIssue>
     */
    private function generateFrequentQueryIssues(array $queryFrequencies, array $queryDetails): \Generator
    {
        assert(is_iterable($queryFrequencies), '$queryFrequencies must be iterable');

        foreach ($queryFrequencies as $normalized => $count) {
            if ($count < self::FREQUENCY_THRESHOLD_INFO) {
                continue;
            }

            $details = $queryDetails[$normalized];

            // Only suggest caching for SELECT queries
            // INSERT/UPDATE/DELETE queries cannot be cached
            if ($this->isSelectQuery($details['originalSql'])) {
                yield $this->createFrequentQueryIssue(
                    $details['originalSql'],
                    $count,
                    $details['totalTime'],
                    $details['backtrace'],
                );
            }
        }
    }

    /**
     * Generate issues for queries on static tables.
     * @return \Generator<PerformanceIssue>
     */
    private function generateStaticTableIssues(QueryDataCollection $queryDataCollection): \Generator
    {
        $reportedStaticTables = [];

        assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

        foreach ($queryDataCollection as $query) {
            $sql = $this->extractSQL($query);

            if ('' === $sql || '0' === $sql) {
                continue;
            }

            // Only suggest caching for SELECT queries on static tables
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
     * Normalize a query by replacing values with placeholders.
     * This allows detecting structurally identical queries.
     */
    private function normalizeQuery(string $sql): string
    {
        // Remove extra whitespace
        $normalized = preg_replace('/\s+/', ' ', trim($sql));
        if (null === $normalized) {
            return $sql;
        }

        // Replace numeric values
        $normalized = preg_replace('/\b\d+\b/', '?', $normalized);
        if (null === $normalized) {
            return $sql;
        }

        // Replace string literals (single and double quotes)
        $normalized = preg_replace("/'[^']*'/", '?', $normalized);
        if (null === $normalized) {
            return $sql;
        }

        $normalized = preg_replace('/"[^"]*"/', '?', $normalized);
        if (null === $normalized) {
            return $sql;
        }

        // Replace IN clause with multiple values
        $normalized = preg_replace('/IN\s*\([^)]+\)/i', 'IN (?)', $normalized);
        if (null === $normalized) {
            return $sql;
        }

        return $normalized;
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
     */
    private function getStaticTableFromQuery(string $sql): ?string
    {
        foreach (self::STATIC_TABLES as $table) {
            // Check for table name (with optional database prefix)
            if (1 === preg_match('/\bFROM\s+(?:\w+\.)?(' . preg_quote($table, '/') . ')\b/i', $sql)) {
                return $table;
            }

            if (1 === preg_match('/\bJOIN\s+(?:\w+\.)?(' . preg_quote($table, '/') . ')\b/i', $sql)) {
                return $table;
            }
        }

        return null;
    }

    /**
     * Create issue for frequently executed query.
     */
    private function createFrequentQueryIssue(
        string $originalSql,
        int $count,
        float $totalTime,
        ?array $backtrace,
    ): PerformanceIssue {
        // Determine severity based on frequency
        $severity = match (true) {
            $count >= self::FREQUENCY_THRESHOLD_CRITICAL => Severity::critical(),
            $count >= self::FREQUENCY_THRESHOLD_WARNING => Severity::warning(),
            default => Severity::info(),
        };

        // Calculate cache performance improvement
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
            queries: [],
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
            queries: [],
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
            templateName: 'query_caching_frequent',
            context: [
                'sql' => $sql,
                'count' => $count,
                'total_time' => $totalTime,
                'avg_time' => $totalTime / $count,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $count >= 10 ? Severity::critical() : Severity::warning(),
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
            templateName: 'query_caching_static',
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
}
