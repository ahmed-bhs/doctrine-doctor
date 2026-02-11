<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\QueryColumnExtractor;
use AhmedBhs\DoctrineDoctor\Cache\SqlNormalizationCache;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\MissingIndexIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Analyzes queries for missing indexes using EXPLAIN.
 * Platform compatibility:
 * - MySQL: Full support - EXPLAIN analysis
 * - MariaDB: Full support - EXPLAIN analysis
 * - PostgreSQL: Full support - EXPLAIN analysis (different output format)
 * - Doctrine DBAL: 2.x and 3.x+ compatible
 */
class MissingIndexAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    public function __construct(
        private readonly SuggestionFactory $suggestionFactory,
        private readonly Connection $connection,
        private readonly MissingIndexAnalyzerConfig $missingIndexAnalyzerConfig = new MissingIndexAnalyzerConfig(),
        private readonly ?DatabasePlatformDetector $databasePlatformDetector = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly QueryColumnExtractor $queryColumnExtractor = new QueryColumnExtractor(),
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                if (!$this->missingIndexAnalyzerConfig->enabled) {
                    return;
                }

                $debugStats    = $this->initializeDebugStats($queryDataCollection->count());
                $queriesArray  = $queryDataCollection->toArray();
                $queryPatterns = $this->collectQueryPatterns($queriesArray);
                $queriesToExplain = $this->selectQueriesToExplain($queriesArray, $queryPatterns, $debugStats);

                yield from $this->analyzeSelectedQueries($queriesToExplain, $debugStats);

                $this->finalizeDebugStats($debugStats);
            },
        );
    }

    /**
     * Initialize debug statistics.
     * @return array<string, int|float|array<mixed>>
     */
    private function initializeDebugStats(int $totalQueries): array
    {
        return [
            'total_queries'      => $totalQueries,
            'slow_queries'       => 0,
            'repetitive_queries' => 0,
            'explain_attempts'   => 0,
            'explain_success'    => 0,
            'index_suggestions'  => 0,
            'max_execution_time' => 0,
        ];
    }

    /**
     * Collect query patterns for repetition detection.
     * @param array<QueryData> $queriesArray
     * @return array<string, array{count: int, sample_query: QueryData}>
     */
    private function collectQueryPatterns(array $queriesArray): array
    {
        $queryPatterns = [];

        Assert::isIterable($queriesArray, '$queriesArray must be iterable');

        foreach ($queriesArray as $queryArray) {
            $pattern = $this->normalizeQuery($queryArray->sql);

            if (!isset($queryPatterns[$pattern])) {
                $queryPatterns[$pattern] = [
                    'count'        => 0,
                    'sample_query' => $queryArray,
                ];
            }

            ++$queryPatterns[$pattern]['count'];
        }

        return $queryPatterns;
    }

    /**
     * Select queries that need EXPLAIN analysis.
     * @param array<QueryData> $queriesArray
     * @param array<string, array{count: int, sample_query: QueryData}> $queryPatterns
     * @param array<string, int|float|array<mixed>> $debugStats
     * @return array<string, QueryData>
     */
    private function selectQueriesToExplain(array $queriesArray, array $queryPatterns, array &$debugStats): array
    {
        $queriesToExplain = [];

        Assert::isIterable($queriesArray, '$queriesArray must be iterable');

        foreach ($queriesArray as $queryArray) {
            $executionTime = $queryArray->executionTime->inMilliseconds();
            $maxTime       = $debugStats['max_execution_time'];
            Assert::numeric($maxTime);
            $debugStats['max_execution_time'] = max($maxTime, $executionTime);

            $pattern      = $this->normalizeQuery($queryArray->sql);
            $isRepetitive = $queryPatterns[$pattern]['count'] >= 3;

            if ($executionTime >= $this->missingIndexAnalyzerConfig->slowQueryThreshold) {
                $slowQueries = $debugStats['slow_queries'];
                Assert::integer($slowQueries);
                $debugStats['slow_queries'] = $slowQueries + 1;
                $queriesToExplain[$pattern] = $queryArray;
            } elseif ($isRepetitive && !isset($queriesToExplain[$pattern])) {
                $repetitiveQueries = $debugStats['repetitive_queries'];
                Assert::integer($repetitiveQueries);
                $debugStats['repetitive_queries'] = $repetitiveQueries + 1;
                $queriesToExplain[$pattern] = $queryArray;
            }
        }

        $debugStats['queries_to_explain']       = count($queriesToExplain);
        $debugStats['explain_results']          = [];
        $debugStats['should_suggest_decisions'] = [];

        return $queriesToExplain;
    }

    /**
     * Analyze selected queries and yield issues.
     * @param array<string, QueryData> $queriesToExplain
     * @param array<string, int|float|array<mixed>> $debugStats
     * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function analyzeSelectedQueries(array $queriesToExplain, array &$debugStats): \Generator
    {
        Assert::isIterable($queriesToExplain, '$queriesToExplain must be iterable');

        foreach ($queriesToExplain as $pattern => $queryData) {
            try {
                yield from $this->analyzeQueryWithExplain($pattern, $queryData, $debugStats);
            } catch (\Throwable $e) {
                $this->recordExplainError($debugStats, $pattern, $e);
            }
        }
    }

    /**
     * Analyze a single query with EXPLAIN.
     * @param array<string, int|float|array<mixed>> $debugStats
     * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function analyzeQueryWithExplain(string $pattern, QueryData $queryData, array &$debugStats): \Generator
    {
        $attempts = $debugStats['explain_attempts'];
        Assert::integer($attempts);
        $debugStats['explain_attempts'] = $attempts + 1;

        $explain = $this->executeExplain($queryData);

        if ([] !== $explain) {
            $success = $debugStats['explain_success'];
            Assert::integer($success);
            $debugStats['explain_success'] = $success + 1;
            $this->recordExplainResult($debugStats, $pattern, $explain);
        }

        yield from $this->processExplainRows($explain, $queryData, $debugStats);
    }

    /**
     * Process EXPLAIN rows and yield issues.
     * @param array<mixed> $explain
     * @param array<string, int|float|array<mixed>> $debugStats
     * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function processExplainRows(array $explain, QueryData $queryData, array &$debugStats): \Generator
    {
        Assert::isIterable($explain, '$explain must be iterable');

        foreach ($explain as $row) {
            Assert::isArray($row);
            $shouldSuggest = $this->shouldSuggestIndex($row);

            $this->recordSuggestionDecision($debugStats, $row, $shouldSuggest);

            if ($shouldSuggest) {
                $suggestions = $debugStats['index_suggestions'];
                Assert::integer($suggestions);
                $debugStats['index_suggestions'] = $suggestions + 1;
                yield $this->createMissingIndexIssue($row, $queryData);
            }
        }
    }

    /**
     * Create a missing index issue.
     */
    private function createMissingIndexIssue(array $row, QueryData $queryData): MissingIndexIssue
    {
        return new MissingIndexIssue([
            'table'        => $row['table'],
            'query'        => $queryData->sql,
            'rows_scanned' => $row['rows'] ?? 0,
            'suggestion'   => $this->suggestIndex($queryData->sql, $row),
            'severity'     => $this->calculateSeverity($row),
            'backtrace'    => $queryData->backtrace,
            'queries'      => [$queryData->toArray()],
        ]);
    }

    /**
     * Record EXPLAIN result for debugging.
     * @param array<string, int|float|array<mixed>> $debugStats
     * @param array<mixed> $explain
     */
    private function recordExplainResult(array &$debugStats, string $pattern, array $explain): void
    {
        $explainResults = $debugStats['explain_results'] ?? [];
        Assert::isArray($explainResults);

        if (count($explainResults) < 3) {
            /** @var array<mixed> $explainResultsTyped */
            $explainResultsTyped   = $explainResults;
            $explainResultsTyped[] = [
                'pattern' => substr($pattern, 0, 100),
                'explain' => $explain,
            ];
            $debugStats['explain_results'] = $explainResultsTyped;
        }
    }

    /**
     * Record suggestion decision for debugging.
     * @param array<string, int|float|array<mixed>> $debugStats
     * @param array<mixed> $row
     */
    private function recordSuggestionDecision(array &$debugStats, array $row, bool $shouldSuggest): void
    {
        $decisions = $debugStats['should_suggest_decisions'] ?? [];
        Assert::isArray($decisions);

        if (count($decisions) < 5) {
            /** @var array<mixed> $decisionsTyped */
            $decisionsTyped   = $decisions;
            $decisionsTyped[] = [
                'table'              => $row['table'] ?? 'N/A',
                'type'               => $row['type'] ?? 'N/A',
                'key'                => $row['key'] ?? 'NULL',
                'rows'               => $row['rows'] ?? 0,
                'should_suggest'     => $shouldSuggest,
                'min_rows_threshold' => $this->missingIndexAnalyzerConfig->minRowsScanned,
            ];
            $debugStats['should_suggest_decisions'] = $decisionsTyped;
        }
    }

    /**
     * Record EXPLAIN error for debugging.
     * @param array<string, int|float|array<mixed>> $debugStats
     */
    private function recordExplainError(array &$debugStats, string $pattern, \Throwable $throwable): void
    {
        $errors = $debugStats['explain_errors'] ?? [];
        Assert::isArray($errors);

        /** @var array<mixed> $errorsTyped */
        $errorsTyped   = $errors;
        $errorsTyped[] = [
            'pattern' => substr($pattern, 0, 100),
            'error'   => $throwable->getMessage(),
        ];
        $debugStats['explain_errors'] = $errorsTyped;
    }

    /**
     * Finalize and log debug statistics.
     * @param array<string, int|float|bool|array<mixed>> $debugStats
     */
    private function finalizeDebugStats(array $debugStats): void
    {
        $debugStats['threshold_ms']     = $this->missingIndexAnalyzerConfig->slowQueryThreshold;
        $debugStats['min_rows_scanned'] = $this->missingIndexAnalyzerConfig->minRowsScanned;
        $debugStats['explain_enabled']  = $this->missingIndexAnalyzerConfig->enabled;

        if (isset($debugStats['explain_errors']) && is_array($debugStats['explain_errors']) && count($debugStats['explain_errors']) > 0) {
            $this->logger?->error('MissingIndexAnalyzer encountered EXPLAIN errors', [
                'explain_errors' => $debugStats['explain_errors'],
                'total_queries' => $debugStats['total_queries'],
                'explain_attempts' => $debugStats['explain_attempts'],
                'explain_success' => $debugStats['explain_success'],
            ]);
        }

        $this->logger?->debug('MissingIndexAnalyzer Stats', $debugStats);
    }

    private function executeExplain(QueryData $queryData): array
    {
        $sql    = $queryData->sql;
        $params = $queryData->params;

        if (1 !== preg_match('/^\s*SELECT/i', $sql)) {
            return [];
        }

        if (is_object($params) && method_exists($params, 'getValue')) {
            $params = $params->getValue(true);
        }

        if (!is_array($params)) {
            $params = [];
        }

        try {
            $detector = $this->databasePlatformDetector ?? new DatabasePlatformDetector($this->connection);

            $explainQuery = $detector->getExplainQuery($sql);

            $result = $this->connection->executeQuery(
                $explainQuery,
                $params,
            );

            $rows = $detector->fetchAllAssociative($result);

            return $this->normalizeExplainOutput($rows, $detector);
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Normalize EXPLAIN output between MySQL, PostgreSQL, and SQLite.
     */
    private function normalizeExplainOutput(array $rows, DatabasePlatformDetector $databasePlatformDetector): array
    {
        if ($databasePlatformDetector->isPostgreSQL()) {
            return array_map(function (array $row): array {
                $plan = $row['QUERY PLAN'] ?? '';

                return [
                    'table'         => $this->extractTableFromPostgreSQLPlan($plan),
                    'type'          => $this->extractTypeFromPostgreSQLPlan($plan),
                    'key'           => null, // PostgreSQL doesn't have direct equivalent
                    'rows'          => $this->extractRowsFromPostgreSQLPlan($plan),
                    'possible_keys' => null,
                    'Extra'         => $plan,
                ];
            }, $rows);
        }

        if ($databasePlatformDetector->isSQLite()) {
            return array_map(function (array $row): array {
                $detail = $row['detail'] ?? '';

                return [
                    'table'         => $this->extractTableFromSQLitePlan($detail),
                    'type'          => $this->extractTypeFromSQLitePlan($detail),
                    'key'           => $this->extractKeyFromSQLitePlan($detail),
                    'rows'          => $this->extractRowsFromSQLitePlan($detail),
                    'possible_keys' => null,
                    'Extra'         => $detail,
                ];
            }, $rows);
        }

        return $rows;
    }

    private function extractTableFromPostgreSQLPlan(string $plan): ?string
    {
        if (1 === preg_match('/\s+on\s+(\w+)/i', $plan, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractTypeFromPostgreSQLPlan(string $plan): string
    {
        if (false !== stripos($plan, 'Seq Scan')) {
            return 'ALL'; // Sequential scan = full table scan
        }

        if (false !== stripos($plan, 'Index Scan')) {
            return 'ref'; // Index scan
        }

        if (false !== stripos($plan, 'Index Only Scan')) {
            return 'index'; // Covering index
        }

        if (false !== stripos($plan, 'Bitmap')) {
            return 'range'; // Bitmap scan ~ range scan
        }

        return 'unknown';
    }

    private function extractRowsFromPostgreSQLPlan(string $plan): int
    {
        if (1 === preg_match('/rows=(\d+)/i', $plan, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function extractTableFromSQLitePlan(string $detail): ?string
    {
        if (1 === preg_match('/(?:SCAN|SEARCH)\s+(\w+)/i', $detail, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractTypeFromSQLitePlan(string $detail): string
    {
        if (false !== stripos($detail, 'SCAN ')) {
            return 'ALL'; // SCAN = full table scan
        }

        if (false !== stripos($detail, 'SEARCH') && false !== stripos($detail, 'USING INDEX')) {
            return 'ref'; // SEARCH with index
        }

        if (false !== stripos($detail, 'SEARCH')) {
            return 'ALL'; // SEARCH without index
        }

        return 'unknown';
    }

    private function extractKeyFromSQLitePlan(string $detail): ?string
    {
        if (1 === preg_match('/USING INDEX\s+(\w+)/i', $detail, $matches)) {
            return $matches[1];
        }

        return null; // No index used
    }

    private function extractRowsFromSQLitePlan(string $detail): int
    {
        if (false !== stripos($detail, 'SCAN ')) {
            return 1000; // Assume full table scan = many rows
        }

        return 0;
    }

    private function shouldSuggestIndex(array $explainRow): bool
    {
        $table        = $explainRow['table'] ?? null;
        $key          = $explainRow['key'] ?? null;
        $rows         = (int) ($explainRow['rows'] ?? 0);
        $type         = strtoupper($explainRow['type'] ?? '');
        $possibleKeys = $explainRow['possible_keys'] ?? null;

        if (null === $table) {
            return false;
        }

        if (in_array($type, ['CONST', 'EQ_REF'], true) && null !== $key) {
            return false; // Optimal index usage, no suggestion needed
        }

        if (in_array($type, ['REF', 'RANGE'], true) && null !== $key) {
            return $rows >= $this->missingIndexAnalyzerConfig->minRowsScanned;
        }

        $isFullTableScan = 'ALL' === $type;

        $isFullIndexScan = 'INDEX' === $type;

        $hasPossibleKeysButNotUsed = null === $key && null !== $possibleKeys;

        if ($isFullTableScan) {
            return $rows >= $this->missingIndexAnalyzerConfig->minRowsScanned;
        }

        if ($isFullIndexScan && null === $possibleKeys) {
            return $rows >= $this->missingIndexAnalyzerConfig->minRowsScanned;
        }

        if ($hasPossibleKeysButNotUsed) {
            return $rows >= $this->missingIndexAnalyzerConfig->minRowsScanned;
        }

        return $rows >= $this->missingIndexAnalyzerConfig->minRowsScanned;
    }

    private function calculateSeverity(array $explainRow): Severity
    {
        $rows = $explainRow['rows'] ?? 0;
        $type = strtoupper($explainRow['type'] ?? '');

        if ('ALL' === $type && $rows > 10000) {
            return Severity::CRITICAL;
        }

        if ($rows > 5000) {
            return Severity::CRITICAL;
        }

        return Severity::WARNING;
    }

    private function suggestIndex(string $sql, array $explainRow): ?SuggestionInterface
    {
        if (($explainRow['table'] ?? null) === null) {
            return null;
        }

        $tableAlias    = $explainRow['table'];
        $tableInfo     = $this->extractTableNameWithAlias($sql, $tableAlias);
        $realTableName = $tableInfo['realName'];
        $tableDisplay  = $tableInfo['display'];

        $columns = $this->extractColumnsFromQuery($sql, $explainRow['table']);

        if ([] === $columns) {
            return $this->suggestionFactory->createFromTemplate(
                'missing_index_generic',
                [
                    'table_display'   => $tableDisplay,
                    'real_table_name' => $realTableName,
                    'query'           => $sql,
                    'rows_scanned'    => $explainRow['rows'] ?? 0,
                ],
                new SuggestionMetadata(
                    type: SuggestionType::performance(),
                    severity: Severity::critical(),
                    title: sprintf('Missing Index on %s', $tableDisplay),
                    tags: ['performance', 'index', 'database'],
                ),
            );
        }

        $columnsList = implode(', ', $columns);
        $indexName   = 'IDX_' . strtoupper((string) $realTableName) . '_' . strtoupper(implode('_', $columns));

        return $this->suggestionFactory->createFromTemplate(
            'missing_index',
            [
                'table_display'   => $tableDisplay,
                'real_table_name' => $realTableName,
                'columns_list'    => $columnsList,
                'index_name'      => $indexName,
            ],
            new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::critical(),
                title: sprintf('Missing Index on %s', $tableDisplay),
                tags: ['performance', 'index', 'database'],
            ),
        );
    }

    private function normalizeQuery(string $sql): string
    {
        return SqlNormalizationCache::normalize($sql);
    }

    /**
     * Extracts the real table name from SQL query given its alias
     * Returns array with 'realName' and 'display' (format: "table_name alias").
     * @return array{realName: string, display: string}
     */
    private function extractTableNameWithAlias(string $sql, string $alias): array
    {
        $pattern = '/(?:FROM|JOIN)\s+([`\w]+)\s+(?:AS\s+)?' . preg_quote($alias, '/') . '\b/i';

        if (1 === preg_match($pattern, $sql, $matches)) {
            $realTableName = trim($matches[1], '`');

            return [
                'realName' => $realTableName,
                'display'  => $realTableName . ' ' . $alias,
            ];
        }

        return [
            'realName' => $alias,
            'display'  => $alias,
        ];
    }

    /**
     * Extract columns from query that could benefit from indexing.
     * Delegates to QueryColumnExtractor helper.
     * @return array<string>
     */
    private function extractColumnsFromQuery(string $sql, string $targetTable): array
    {
        return $this->queryColumnExtractor->extractColumns($sql, $targetTable);
    }
}
