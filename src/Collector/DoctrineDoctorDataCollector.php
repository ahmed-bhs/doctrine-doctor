<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\CachedSqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Analyzer\StaticAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Collector\Helper\DataCollectorLogger;
use AhmedBhs\DoctrineDoctor\Collector\Helper\IssueReconstructor;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Infrastructure\Cache\SqlNormalizationCache;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Service\ExportDataFormatter;
use AhmedBhs\DoctrineDoctor\Service\IssueDeduplicator;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueCategory;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Contracts\Service\ResetInterface;

/**
 * DataCollector for Doctrine Doctor.
 *
 * Runtime-dependent analysis timing (see AnalysisTiming):
 * On classic php-fpm, fastcgi_finish_request() flushes the response to the
 * client before kernel.terminate, so analysis is deferred to lateCollect()
 * to keep the (often EXPLAIN-heavy) analyzers off the request's critical path.
 *
 * Everywhere else (Apache mod_php, FrankenPHP worker mode, RoadRunner, Swoole)
 * lateCollect() would still block the response, or race the next request once
 * the EntityManager is invalid. There, collect() only stores the queries and
 * the analysis runs when the profile is opened in the web debug toolbar or the
 * profiler (PendingAnalysisSubscriber), or by doctrine-doctor:profile:analyze.
 */
/**
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class DoctrineDoctorDataCollector extends DataCollector implements LateDataCollectorInterface, ResetInterface
{
    private const int MAX_QUERIES_PER_REQUEST = 5000;

    private ?array $memoizedIssues = null;

    private ?array $memoizedDatabaseInfo = null;

    private ?array $memoizedStats = null;

    private ?array $memoizedDebugData = null;

    private readonly AnalysisTiming $analysisTiming;

    /**
     * Where deferred analysis results are read from, for collectors loaded from
     * the profiler storage (they have no services). Set when the bundle boots.
     */
    private static ?AnalysisResultStore $resultStore = null;

    public function __construct(
        /**
         * @var AnalyzerInterface[]
         */
        private readonly iterable $analyzers,
        private readonly ?DoctrineDataCollector $doctrineDataCollector,
        private readonly ?EntityManagerInterface $entityManager,
        private readonly ?Stopwatch $stopwatch,
        private readonly bool $showDebugInfo,
        private readonly DataCollectorHelpers $dataCollectorHelpers,
        /**
         * @var array<string> Paths to exclude from DBAL query analysis (e.g., ['vendor/', 'var/cache/'])
         */
        private readonly array $excludePaths = ['vendor/'],
        /**
         * Whether to defer analysis to lateCollect() (after the response is sent
         * to the client) instead of running it in collect(). Defaults to whether
         * fastcgi_finish_request() exists: present on classic php-fpm (deferring
         * keeps analysis off the request's critical path), absent on persistent
         * runtimes (FrankenPHP worker mode, RoadRunner, Swoole) where the
         * EntityManager some analyzers depend on becomes invalid once the request
         * ends, so analysis must still run in collect().
         *
         * @deprecated use $analysisTiming instead; kept for backward compatibility
         */
        ?bool $deferAnalysisToLateCollect = null,
        /**
         * When the runtime analysis runs. Takes precedence over $deferAnalysisToLateCollect.
         * Defaults to AnalysisTiming::Auto (after the response on php-fpm, when the
         * profile is viewed everywhere else).
         */
        ?AnalysisTiming $analysisTiming = null,
        /**
         * Runtime switch, resolved per request: `doctrine_doctor.enabled` may be an
         * env var (e.g. %env(bool:DOCTRINE_DOCTOR_ENABLED)%), which can change
         * without rebuilding the container.
         */
        private readonly bool $enabled = true,
    ) {
        $this->analysisTiming = match (true) {
            null !== $analysisTiming              => $analysisTiming->resolve(),
            true === $deferAnalysisToLateCollect  => AnalysisTiming::AfterResponse,
            false === $deferAnalysisToLateCollect => AnalysisTiming::Request,
            default                               => AnalysisTiming::Auto->resolve(),
        };
    }

    /**
     * @SuppressWarnings(UnusedFormalParameter)
     */
    public function collect(Request $_request, Response $_response, ?\Throwable $_exception = null): void
    {
        $this->data = [
            'enabled'           => (bool) $this->doctrineDataCollector,
            'show_debug_info'   => $this->showDebugInfo,
            'timeline_queries'  => [],
            'issues'            => [],
            'skipped_analyzers' => 0,
            'analyzer_stats'    => [],
            'database_info'     => [],
            'profiler_overhead' => [
                'analysis_time_ms' => 0,
                'db_info_time_ms'  => 0,
                'total_time_ms'    => 0,
            ],
        ];

        if (!$this->enabled) {
            $this->data['enabled'] = false;

            return;
        }

        if (!$this->doctrineDataCollector instanceof DoctrineDataCollector) {
            return;
        }

        $queries = $this->doctrineDataCollector->getQueries();

        foreach ($queries as $query) {
            if (is_array($query)) {
                foreach ($query as $connectionQuery) {
                    $this->data['timeline_queries'][] = $connectionQuery;
                }
            }
        }

        match ($this->analysisTiming) {
            AnalysisTiming::Request => $this->analyze(),
            AnalysisTiming::OnView  => $this->markAnalysisPending(),
            default                 => null,
        };
    }

    public function lateCollect(): void
    {
        // Instances unserialized from profiler storage have no services and no
        // timing: they must never analyze (see completePendingAnalysis()).
        if (!isset($this->analysisTiming) || AnalysisTiming::AfterResponse !== $this->analysisTiming) {
            return;
        }

        if ($this->data['enabled'] ?? false) {
            $this->analyze();
        }
    }

    public static function useResultStore(?AnalysisResultStore $resultStore): void
    {
        self::$resultStore = $resultStore;
    }

    /**
     * Whether the queries of this (stored) profile still have to be analyzed.
     */
    public function isAnalysisPending(): bool
    {
        $this->resolvePendingAnalysis();

        return true === ($this->data['analysis_pending'] ?? false);
    }

    /**
     * Key of the deferred analysis result in the AnalysisResultStore.
     */
    public function getAnalysisKey(): ?string
    {
        $key = $this->data['analysis_key'] ?? null;

        return \is_string($key) ? $key : null;
    }

    /**
     * Load the result of a deferred analysis, if it has been stored.
     *
     * @return bool whether this collector now holds an analysis result
     */
    public function resolvePendingAnalysis(?AnalysisResultStore $resultStore = null): bool
    {
        if (true !== ($this->data['analysis_pending'] ?? false)) {
            return false;
        }

        $key = $this->getAnalysisKey();
        $result = null !== $key ? ($resultStore ?? self::$resultStore)?->load($key) : null;

        if (null === $result) {
            return false;
        }

        $this->data = array_merge(\is_array($this->data) ? $this->data : [], $result);
        unset($this->data['analysis_pending']);
        $this->clearState(keepData: true);

        return true;
    }

    /**
     * The part of the collected data produced by the analysis (what the
     * AnalysisResultStore keeps for a deferred analysis).
     *
     * @return array<string, mixed>
     */
    public function getAnalysisResult(): array
    {
        if (!\is_array($this->data)) {
            return [];
        }

        return array_diff_key($this->data, array_flip(['timeline_queries', 'analysis_pending', 'analysis_key', 'enabled', 'show_debug_info']));
    }

    /**
     * Run the deferred analysis of a profile loaded from the profiler storage.
     *
     * Called on the live collector service (which holds the analyzers and the
     * EntityManager) with the collector unserialized from the stored profile.
     * The result is written into $profiled, which the caller then persists.
     *
     * @return bool whether an analysis was run
     */
    public function completePendingAnalysis(self $profiled): bool
    {
        if (!$profiled->isAnalysisPending()) {
            return false;
        }

        $this->clearState();
        $this->data = $profiled->data;
        unset($this->data['analysis_pending']);

        try {
            $this->analyze();
            $profiled->data = $this->data;
            $profiled->clearState(keepData: true);
        } finally {
            $this->clearState();
        }

        return true;
    }

    public function getName(): string
    {
        return 'doctrine_doctor';
    }

    /**
     * Reset collector state between requests.
     *
     * This method is called by Symfony's services_resetter after each request.
     * Critical for FrankenPHP worker mode compatibility:
     * - ServiceHolder stores EntityManager and other Doctrine objects
     * - In worker mode, these objects become invalid after request ends
     * - Without clearing, next request causes segfault when accessing stale objects
     *
     * Performance optimization:
     * SQL caches (SqlNormalizationCache, CachedSqlStructureExtractor) are NOT cleared.
     * They only contain strings/arrays (no Doctrine object references), so they're
     * safe to keep across requests and provide significant performance benefits
     * in worker mode where the same queries are often executed repeatedly.
     */
    #[\Override]
    public function reset(): void
    {
        ServiceHolder::clearAll();

        $this->clearState();
    }

    /**
     * Get all issues with memoization.
     *  Data already analyzed during collect() with generators
     *  Memoization: Objects reconstructed once, cached for subsequent calls
     * @return IssueInterface[]
     */
    public function getIssues(): array
    {
        $this->resolvePendingAnalysis();

        if (null !== $this->memoizedIssues) {
            return $this->memoizedIssues;
        }

        if (!($this->data['enabled'] ?? false)) {
            $this->memoizedIssues = [];

            return [];
        }

        $issuesData = $this->data['issues'] ?? [];
        if ([] === $issuesData) {
            $this->memoizedIssues = [];

            return [];
        }

        $issueReconstructor = $this->resolveIssueReconstructor();

        $this->memoizedIssues = array_map(
            $issueReconstructor->reconstructIssue(...),
            $issuesData,
        );

        return $this->memoizedIssues;
    }

    /**
     * Get issues by category with IssueCollection.
     *  OPTIMIZED: Uses IssueCollection for lazy filtering
     * @return IssueInterface[]
     */
    public function getIssuesByCategory(string|IssueCategory $category): array
    {
        $normalizedCategory = is_string($category) ? IssueCategory::fromString($category) : $category;
        $issueCollection = IssueCollection::fromArray($this->getIssues());

        $filtered = $issueCollection->filter(function (IssueInterface $issue) use ($normalizedCategory): bool {
            if (!method_exists($issue, 'getCategory')) {
                return false;
            }

            return $issue->getCategory() === $normalizedCategory;
        });

        return $filtered->toArray();
    }

    /**
     * Get count of issues by category.
     */
    public function getIssueCountByCategory(string|IssueCategory $category): int
    {
        return count($this->getIssuesByCategory($category));
    }

    /**
     * Get stats with memoization.
     *  OPTIMIZED: Uses IssueCollection methods (single pass instead of 3)
     */
    public function getStats(): array
    {
        $this->resolvePendingAnalysis();

        if (null !== $this->memoizedStats) {
            return $this->memoizedStats;
        }

        if (isset($this->data['stats'])) {
            $this->memoizedStats = $this->data['stats'];

            return $this->memoizedStats;
        }

        $issueCollection = IssueCollection::fromArray($this->getIssues());
        $counts          = $issueCollection->statistics()->countBySeverity();

        $this->memoizedStats = [
            'total_issues'       => $issueCollection->count(),
            'critical'           => $counts['critical'] ?? 0,
            'warning'            => $counts['warning'] ?? 0,
            'info'               => $counts['info'] ?? 0,
            'skipped_analyzers'  => $this->data['skipped_analyzers'] ?? 0,
        ];

        return $this->memoizedStats;
    }

    /**
     * Get timeline queries as generator (memory efficient).
     * Returns queries stored during collect().
     *  OPTIMIZED: Returns generator to avoid memory copies
     */
    public function getTimelineQueries(): \Generator
    {
        $queries = $this->data['timeline_queries'] ?? [];

        foreach ($queries as $query) {
            yield $query;
        }
    }

    /**
     * Get timeline queries as array (for backward compatibility).
     * Use getTimelineQueries() for better memory efficiency.
     */
    #[\Deprecated(message: 'Use getTimelineQueries() generator for better performance')]
    public function getTimelineQueriesArray(): array
    {
        return iterator_to_array($this->getTimelineQueries());
    }

    /**
     * Group queries by SQL and calculate statistics (count, total time, avg time).
     * Returns an array of grouped queries sorted by total execution time (descending).
     *
     * @return array<int, array{
     *     sql: string,
     *     count: int,
     *     totalTimeMs: float,
     *     avgTimeMs: float,
     *     maxTimeMs: float,
     *     minTimeMs: float,
     *     firstQuery: array
     * }>
     */
    public function getGroupedQueriesByTime(): array
    {
        if (!isset($this->data['timeline_queries'])) {
            return [];
        }

        /** @var array<string, array{sql: string, count: int, totalTimeMs: float, avgTimeMs: float, maxTimeMs: float, minTimeMs: float, firstQuery: array}> $grouped */
        $grouped = [];

        foreach ($this->getTimelineQueries() as $query) {
            $rawSql = $query['sql'] ?? '';
            $sql = is_string($rawSql) ? $rawSql : '';
            $executionTime = (float) ($query['executionMS'] ?? 0.0);
            $executionMs = $executionTime * QueryExecutionTime::MS_PER_SECOND;

            $grouped[$sql] ??= [
                'sql' => $sql,
                'count' => 0,
                'totalTimeMs' => 0.0,
                'avgTimeMs' => 0.0,
                'maxTimeMs' => 0.0,
                'minTimeMs' => PHP_FLOAT_MAX,
                'firstQuery' => $query, // Keep first occurrence for display
            ];

            $grouped[$sql]['count']++;
            $grouped[$sql]['totalTimeMs'] += $executionMs;
            $grouped[$sql]['maxTimeMs'] = max($grouped[$sql]['maxTimeMs'], $executionMs);
            $grouped[$sql]['minTimeMs'] = min($grouped[$sql]['minTimeMs'], $executionMs);
        }

        foreach ($grouped as $sql => $group) {
            $grouped[$sql]['avgTimeMs'] = $group['totalTimeMs'] / $group['count'];
        }

        $result = array_values($grouped);
        usort($result, fn (array $queryA, array $queryB): int => $queryB['totalTimeMs'] <=> $queryA['totalTimeMs']);

        return $result;
    }

    /**
     * Build the JSON export payload for the profiler panel's download button.
     *
     * Rendered inline into the panel rather than served from a route, so the
     * export works without registering any routing in the host application.
     */
    public function getExportJson(): string
    {
        $payload = new ExportDataFormatter()->format(
            $this->getIssues(),
            $this->getStats(),
            $this->getGroupedQueriesByTime(),
        );

        // JSON_HEX_TAG is required, not cosmetic: the payload is inlined in a
        // <script> block, and captured SQL can contain both "</script>" and the
        // "<!--<script" sequence that flips the HTML parser into double-escaped
        // state, where a later "</script>" no longer closes the element. Escaping
        // angle brackets to < / > makes the payload inert while staying
        // valid JSON. Output is not pretty-printed because it ships on every panel
        // render; the downloaded file is still well-formed JSON.
        return json_encode(
            $payload,
            \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR,
        ) ?: '{}';
    }

    /**
     * Get debug data with memoization.
     *  Data already collected during collect().
     */
    public function getDebug(): array
    {
        $this->resolvePendingAnalysis();

        if (!($this->data['show_debug_info'] ?? false)) {
            return [];
        }

        if (null !== $this->memoizedDebugData) {
            return $this->memoizedDebugData;
        }

        $this->memoizedDebugData = $this->data['debug_data'] ?? [];

        return $this->memoizedDebugData;
    }

    public function isDebugInfoEnabled(): bool
    {
        return $this->data['show_debug_info'] ?? false;
    }

    /**
     * Get database info with memoization.
     *  Data already collected during collect().
     */
    public function getDatabaseInfo(): array
    {
        $this->resolvePendingAnalysis();

        if (null !== $this->memoizedDatabaseInfo) {
            return $this->memoizedDatabaseInfo;
        }

        $this->memoizedDatabaseInfo = $this->data['database_info'] ?? [
            'driver'              => 'N/A',
            'database_version'    => 'N/A',
            'doctrine_version'    => 'N/A',
            'is_deprecated'       => false,
            'deprecation_message' => null,
        ];

        return $this->memoizedDatabaseInfo;
    }

    /**
     * Get profiler overhead metrics.
     * This shows the time spent by Doctrine Doctor analysis, which should be
     * excluded from application performance metrics.
     * @return array{analysis_time_ms: float, db_info_time_ms: float, total_time_ms: float}
     */
    public function getProfilerOverhead(): array
    {
        $this->resolvePendingAnalysis();

        return $this->data['profiler_overhead'] ?? [
            'analysis_time_ms' => 0,
            'db_info_time_ms'  => 0,
            'total_time_ms'    => 0,
        ];
    }

    private static function getMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit');

        if ('-1' === $limit || false === $limit) {
            return \PHP_INT_MAX;
        }

        $value = (int) $limit;
        $unit = strtolower(substr(trim($limit), -1));

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function runAnalysis(): void
    {
        $analysisStartedAt = hrtime(true);
        $this->stopwatch?->start('doctrine_doctor.analysis', 'doctrine_doctor_profiling');

        $runtimeAnalyzers = iterator_to_array($this->getRuntimeAnalyzers(), false);

        if ([] !== $this->data['timeline_queries']) {
            SqlNormalizationCache::warmUp($this->data['timeline_queries']);
        }

        $this->data['issues'] = $this->analyzeQueriesLazy(
            $runtimeAnalyzers,
            $this->dataCollectorHelpers->dataCollectorLogger,
            $this->dataCollectorHelpers->issueDeduplicator,
        );

        $this->data['stats'] = $this->computeStatsFromRawIssues($this->data['issues']);

        $analysisEvent = $this->stopwatch?->stop('doctrine_doctor.analysis');

        $duration = is_object($analysisEvent) && method_exists($analysisEvent, 'getDuration')
            ? (float) $analysisEvent->getDuration()
            : (hrtime(true) - $analysisStartedAt) / 1_000_000;
        $this->data['profiler_overhead']['analysis_time_ms'] = round($duration, 2);
        $this->data['profiler_overhead']['total_time_ms'] = round($duration + $this->data['profiler_overhead']['db_info_time_ms'], 2);

        if ($this->showDebugInfo) {
            $analyzersList = [];
            $analyzerStats = $this->data['analyzer_stats'];

            uasort($analyzerStats, static fn (array $left, array $right): int => $right['execution_time_ms'] <=> $left['execution_time_ms']);

            foreach ($runtimeAnalyzers as $analyzer) {
                $analyzersList[] = $analyzer::class;
            }

            $this->data['debug_data'] = [
                'total_queries'             => count($this->data['timeline_queries']),
                'doctrine_collector_exists' => true,
                'analyzers_count'           => count($analyzersList),
                'analyzers_list'            => $analyzersList,
                'analyzer_stats'            => $analyzerStats,
                'query_time_stats'          => $this->dataCollectorHelpers->queryStatsCalculator->calculateStats($this->data['timeline_queries']),
                'profiler_overhead_ms'      => $this->data['profiler_overhead']['total_time_ms'],
            ];
        }
    }

    /**
     * @return \Generator<int, AnalyzerInterface>
     */
    private function getRuntimeAnalyzers(): \Generator
    {
        foreach ($this->analyzers as $analyzer) {
            if (!$analyzer instanceof StaticAnalyzerInterface) {
                yield $analyzer;
            }
        }
    }

    /**
     * @param iterable              $analyzers           Analyzers from static cache
     * @param DataCollectorLogger   $dataCollectorLogger Logger for conditional logging
     * @param IssueDeduplicator     $issueDeduplicator   Service to deduplicate redundant issues
     * @return array Array of issue data (not objects yet)
     */
    /**
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    private function analyzeQueriesLazy(
        iterable $analyzers,
        DataCollectorLogger $dataCollectorLogger,
        IssueDeduplicator $issueDeduplicator,
    ): array {
        $queries = $this->data['timeline_queries'] ?? [];

        $dataCollectorLogger->logInfoIfEnabled(sprintf('analyzeQueriesLazy() called with %d queries', count($queries)));

        $filteredQueries = $queries;
        if ([] !== $this->excludePaths) {
            $filteredQueries = $this->filterQueriesByPaths($queries, $this->excludePaths);
        }

        $queryDTOs = [];
        $maxQueries = self::MAX_QUERIES_PER_REQUEST;
        $droppedQueries = 0;

        foreach ($filteredQueries as $query) {
            if (count($queryDTOs) >= $maxQueries) {
                ++$droppedQueries;
                continue;
            }

            try {
                $queryDTOs[] = QueryData::fromArray($query);
            } catch (\Throwable $e) {
                $dataCollectorLogger->logWarningIfDebugEnabled('Failed to convert query to DTO', $e);
            }
        }

        if ($droppedQueries > 0) {
            $dataCollectorLogger->logWarningIfDebugEnabled(
                sprintf('Query collection capped at %d entries; %d queries dropped to prevent memory exhaustion.', $maxQueries, $droppedQueries),
                new \RuntimeException('query_cap_reached'),
            );
        }

        $queryCollection = QueryDataCollection::fromArray($queryDTOs);
        unset($queryDTOs);

        $allIssues = [];
        $memoryThreshold = (int) (self::getMemoryLimitBytes() * 0.70);
        $issueCount = 0;

        foreach ($analyzers as $analyzer) {
            if (0 === $issueCount % 50 && memory_get_usage(true) >= $memoryThreshold) {
                ++$this->data['skipped_analyzers'];

                continue;
            }

            $analyzerStartedAt = $this->showDebugInfo ? hrtime(true) : null;
            $analyzerIssueCount = 0;

            try {
                $issueCollection = $analyzer->analyze($queryCollection);

                foreach ($issueCollection as $issue) {
                    $allIssues[] = $issue;
                    ++$analyzerIssueCount;
                    ++$issueCount;

                    if (0 === $issueCount % 50 && memory_get_usage(true) >= $memoryThreshold) {
                        break;
                    }
                }

                unset($issueCollection);
            } catch (\Throwable $e) {
                $dataCollectorLogger->logErrorIfDebugEnabled('Analyzer failed: ' . $analyzer::class, $e);

                $allIssues[] = new \AhmedBhs\DoctrineDoctor\Issue\ConfigurationIssue([
                    'type' => 'analyzer_failure',
                    'title' => 'Analyzer Failure: ' . new \ReflectionClass($analyzer)->getShortName(),
                    'description' => sprintf(
                        'The analyzer %s failed during execution. This might be due to a bug or an incompatible environment state. Error: %s',
                        $analyzer::class,
                        $e->getMessage(),
                    ),
                    'severity' => 'warning',
                    'queries' => [],
                    'backtrace' => [['file' => $e->getFile(), 'line' => $e->getLine()]],
                ]);
            } finally {
                if (null !== $analyzerStartedAt) {
                    $this->data['analyzer_stats'][$analyzer::class] = [
                        'issues_found'      => $analyzerIssueCount,
                        'execution_time_ms' => round((hrtime(true) - $analyzerStartedAt) / 1_000_000, 2),
                    ];
                }
            }
        }

        if ($this->data['skipped_analyzers'] > 0) {
            $allIssues[] = new \AhmedBhs\DoctrineDoctor\Issue\ConfigurationIssue([
                'type' => 'analysis_incomplete',
                'title' => 'Analysis Incomplete (Low Memory)',
                'description' => sprintf(
                    'To protect your application, %d analyzers were skipped because the PHP memory limit was reached (70%% threshold). Consider increasing your memory_limit if this happens frequently.',
                    $this->data['skipped_analyzers'],
                ),
                'severity' => 'warning',
                'queries' => [],
            ]);
        }

        $issuesCollection = IssueCollection::fromArray($allIssues);
        unset($allIssues);

        $deduplicatedCollection = $issueDeduplicator->deduplicate($issuesCollection);
        unset($issuesCollection);

        $deduplicatedCollection = $deduplicatedCollection->sorting()->bySeverityDescending();

        return $deduplicatedCollection->toArrayOfArrays();
    }

    private function markAnalysisPending(): void
    {
        $this->data['analysis_pending'] = true;
        $this->data['analysis_key'] = bin2hex(random_bytes(16));
    }

    private function analyze(): void
    {
        $this->collectDatabaseInfo();
        $this->runAnalysis();
    }

    private function clearState(bool $keepData = false): void
    {
        if (!$keepData) {
            $this->data = [];
        }

        $this->memoizedIssues       = null;
        $this->memoizedDatabaseInfo = null;
        $this->memoizedStats        = null;
        $this->memoizedDebugData    = null;
    }

    private function collectDatabaseInfo(): void
    {
        $startedAt = hrtime(true);
        $this->data['database_info'] = $this->dataCollectorHelpers->databaseInfoCollector->collectDatabaseInfo($this->entityManager);
        $duration = (hrtime(true) - $startedAt) / 1_000_000;

        $this->data['profiler_overhead']['db_info_time_ms'] = round($duration, 2);
        $this->data['profiler_overhead']['total_time_ms'] += $duration;
    }

    /**
     * @param array<int, array<string, mixed>> $rawIssues
     * @return array{total_issues: int, critical: int, warning: int, info: int, skipped_analyzers: int}
     */
    private function computeStatsFromRawIssues(array $rawIssues): array
    {
        $critical = 0;
        $warning = 0;
        $info = 0;

        foreach ($rawIssues as $issue) {
            $severity = $issue['severity'] ?? 'info';
            match ($severity) {
                'critical' => ++$critical,
                'warning' => ++$warning,
                default => ++$info,
            };
        }

        return [
            'total_issues'      => count($rawIssues),
            'critical'          => $critical,
            'warning'           => $warning,
            'info'              => $info,
            'skipped_analyzers' => $this->data['skipped_analyzers'] ?? 0,
        ];
    }

    private function resolveIssueReconstructor(): IssueReconstructor
    {
        if (new \ReflectionProperty(self::class, 'dataCollectorHelpers')->isInitialized($this)) {
            return $this->dataCollectorHelpers->issueReconstructor;
        }

        return new IssueReconstructor();
    }

    /**
     * Filter raw queries by excluded paths (e.g., vendor/, var/cache/).
     * This is done BEFORE converting to QueryData objects for performance.
     *
     * @param array<int, array<string, mixed>> $queries Raw query arrays from Doctrine DataCollector
     * @param array<string>                    $excludedPaths Paths to exclude (e.g., ['vendor/', 'var/cache/'])
     * @return array<int, array<string, mixed>> Filtered queries
     */
    private function filterQueriesByPaths(array $queries, array $excludedPaths): array
    {
        if ([] === $excludedPaths) {
            return $queries;
        }

        $filtered = [];

        foreach ($queries as $query) {
            if (!$this->isQueryFromExcludedPaths($query, $excludedPaths)) {
                $filtered[] = $query;
            }
        }

        return $filtered;
    }

    /**
     * Check if a raw query originates from excluded paths by analyzing its backtrace.
     *
     * SMART FILTERING LOGIC:
     * Instead of excluding if ANY frame is from vendor/, we find the FIRST application frame
     * (non-vendor, non-cache) and use it to determine if the query should be excluded.
     *
     * Example:
     *   App\Controller\UserController::index()  ← First app frame (NOT in vendor/)
     *     → Symfony\Component\HttpKernel\...     ← vendor (ignored)
     *     → Doctrine\ORM\EntityManager::...      ← vendor (ignored)
     *
     * Result: INCLUDED (because first app frame is from App\Controller, not vendor/)
     *
     * This ensures we analyze queries triggered by YOUR code, even if they go through vendor code.
     *
     * @param array<string, mixed> $queryArray Raw query array with 'backtrace' key
     * @param array<string>        $excludedPaths Paths to check (e.g., ['vendor/', 'var/cache/'])
     */
    private function isQueryFromExcludedPaths(array $queryArray, array $excludedPaths): bool
    {
        $backtrace = $queryArray['backtrace'] ?? null;

        if (null === $backtrace || !is_array($backtrace) || [] === $backtrace) {
            return false;
        }

        $firstAppFrame = null;
        $hasValidFrames = false; // Track if we found at least one valid frame

        // Bootstrap files that appear in every backtrace but are not application code
        $bootstrapFiles = ['index.php', 'autoload_runtime.php', 'autoload.php'];

        foreach ($backtrace as $frame) {
            if (!is_array($frame)) {
                continue;
            }

            $file = $frame['file'] ?? '';

            if ('' === $file || !is_string($file)) {
                continue;
            }

            $hasValidFrames = true;

            $normalizedPath = str_replace('\\', '/', $file);

            // Skip bootstrap entry points — they are not meaningful application frames
            $basename = basename($normalizedPath);
            if (\in_array($basename, $bootstrapFiles, true)) {
                continue;
            }

            $isExcluded = false;
            foreach ($excludedPaths as $excludedPath) {
                $normalizedExcludedPath = str_replace('\\', '/', $excludedPath);

                if (str_contains($normalizedPath, $normalizedExcludedPath)) {
                    $isExcluded = true;
                    break;
                }
            }

            if (!$isExcluded) {
                $firstAppFrame = $normalizedPath;
                break;
            }
        }

        if (null !== $firstAppFrame) {
            return false; // Query originates from application code
        }

        if (!$hasValidFrames) {
            return false;
        }

        return true;
    }
}
