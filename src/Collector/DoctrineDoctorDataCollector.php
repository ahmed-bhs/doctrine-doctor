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
use AhmedBhs\DoctrineDoctor\Cache\SqlNormalizationCache;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Collector\Helper\DataCollectorLogger;
use AhmedBhs\DoctrineDoctor\Collector\Helper\IssueReconstructor;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Service\IssueDeduplicator;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\Stopwatch\StopwatchEvent;
use Webmozart\Assert\Assert;

/**
 * Optimized DataCollector for Doctrine Doctor with Late Collection.
 * Performance optimizations:
 * - Minimal overhead during request (~1-2ms in collect())
 * - Heavy analysis deferred to lateCollect() - runs AFTER response sent to client
 * - Analysis time NOT included in request time metrics
 * - Memoization to avoid repeated calculations
 * - No file I/O or extra SQL queries during request handling
 * - Zero overhead in production (when profiler is disabled)
 * How it works:
 * 1. collect() - Fast, stores raw query data only (~1-2ms)
 * 2. Response sent to client (request time stops here)
 * 3. lateCollect() - Heavy analysis happens here (10-50ms, NOT counted in request time)
 */
class DoctrineDoctorDataCollector extends DataCollector implements LateDataCollectorInterface
{
    private ?array $memoizedIssues = null;

    private ?array $memoizedDatabaseInfo = null;

    private ?array $memoizedStats = null;

    private ?array $memoizedDebugData = null;

    public function __construct(
        /**
         * @var AnalyzerInterface[]
         * @readonly
         */
        private iterable $analyzers,
        /**
         * @readonly
         */
        private ?DoctrineDataCollector $doctrineDataCollector,
        /**
         * @readonly
         */
        private ?EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private ?Stopwatch $stopwatch,
        /**
         * @readonly
         */
        private bool $showDebugInfo,
        /**
         * @readonly
         */
        private DataCollectorHelpers $dataCollectorHelpers,
        /**
         * @var array<string> Paths to exclude from DBAL query analysis (e.g., ['vendor/', 'var/cache/'])
         * @readonly
         */
        private array $excludePaths = ['vendor/'],
    ) {
    }

    /**
     * Fast collect() - stores raw data only, NO heavy analysis.
     * What it does:
     * - Stores raw query data from DoctrineDataCollector (~1-2ms)
     * - Generates unique token for service storage
     * - Stores services in ServiceHolder for lateCollect() access
     * What it does NOT do:
     * - NO query analysis (deferred to lateCollect())
     * - NO database info collection (deferred to lateCollect())
     * - NO heavy processing
     * Result: Minimal impact on request time (~1-2ms only)
     * @SuppressWarnings(UnusedFormalParameter)
     */
    public function collect(Request $_request, Response $_response, ?\Throwable $_exception = null): void
    {
        $token = uniqid('doctrine_doctor_', true);

        $this->data = [
            'enabled'           => (bool) $this->doctrineDataCollector,
            'show_debug_info'   => $this->showDebugInfo,
            'token'             => $token,
            'timeline_queries'  => [],
            'issues'            => [],
            'database_info'     => [
                'driver'              => 'N/A',
                'database_version'    => 'N/A',
                'doctrine_version'    => 'N/A',
                'is_deprecated'       => false,
                'deprecation_message' => null,
            ],
            'profiler_overhead' => [
                'analysis_time_ms' => 0,
                'db_info_time_ms'  => 0,
                'total_time_ms'    => 0,
            ],
        ];

        if (!$this->doctrineDataCollector instanceof DoctrineDataCollector) {
            return;
        }

        $queries = $this->doctrineDataCollector->getQueries();

        Assert::isIterable($queries, '$queries must be iterable');

        foreach ($queries as $query) {
            if (is_array($query)) {
                Assert::isIterable($query, '$query must be iterable');

                foreach ($query as $connectionQuery) {
                    $this->data['timeline_queries'][] = $connectionQuery;
                }
            }
        }

        ServiceHolder::store(
            $token,
            new ServiceHolderData(
                analyzers: $this->analyzers,
                entityManager: $this->entityManager,
                stopwatch: $this->stopwatch,
                databaseInfoCollector: $this->dataCollectorHelpers->databaseInfoCollector,
                issueReconstructor: $this->dataCollectorHelpers->issueReconstructor,
                queryStatsCalculator: $this->dataCollectorHelpers->queryStatsCalculator,
                dataCollectorLogger: $this->dataCollectorHelpers->dataCollectorLogger,
                issueDeduplicator: $this->dataCollectorHelpers->issueDeduplicator,
            ),
        );

        if ($this->showDebugInfo) {
            $analyzersList = [];

            foreach ($this->analyzers as $analyzer) {
                $analyzersList[] = $analyzer::class;
            }

            $this->data['debug_data'] = [
                'total_queries'             => count($this->data['timeline_queries']),
                'doctrine_collector_exists' => true,
                'analyzers_count'           => count($analyzersList),
                'analyzers_list'            => $analyzersList,
                'query_time_stats'          => [], // Will be filled in lateCollect()
                'profiler_overhead_ms'      => 0, // Will be filled in lateCollect()
            ];
        }
    }

    /**
     * Heavy analysis happens here - runs AFTER response sent to client.
     * This is the magic: lateCollect() is called AFTER the HTTP response
     * has been sent to the client, so its execution time is NOT included
     * in the request time metrics shown in the Symfony profiler.
     * What it does:
     * - Retrieves services from ServiceHolder using stored token
     * - Runs heavy query analysis with all analyzers (~10-50ms)
     * - Collects database information
     * - Measures time with Stopwatch (for transparency)
     * - Cleans up ServiceHolder
     * Result: Zero impact on perceived request time!
     */
    public function lateCollect(): void
    {
        $token = $this->data['token'] ?? null;

        if (!$token) {
            return;
        }

        $services = ServiceHolder::get($token);

        if (!$services instanceof ServiceHolderData) {
            return;
        }

        $analyzers             = $services->analyzers;
        $entityManager         = $services->entityManager;
        $stopwatch             = $services->stopwatch;
        $databaseInfoCollector = $services->databaseInfoCollector;
        $queryStatsCalculator  = $services->queryStatsCalculator;
        $dataCollectorLogger   = $services->dataCollectorLogger;
        $issueDeduplicator     = $services->issueDeduplicator;

        $stopwatch?->start('doctrine_doctor.late_total', 'doctrine_doctor_profiling');

        SqlNormalizationCache::warmUp($this->data['timeline_queries']);

        CachedSqlStructureExtractor::warmUp($this->data['timeline_queries']);

        $stopwatch?->start('doctrine_doctor.late_analysis', 'doctrine_doctor_profiling');
        $this->data['issues'] = $this->analyzeQueriesLazy($analyzers, $dataCollectorLogger, $issueDeduplicator);
        $analysisEvent        = $stopwatch?->stop('doctrine_doctor.late_analysis');

        if ($analysisEvent instanceof StopwatchEvent) {
            $this->data['profiler_overhead']['analysis_time_ms'] = $analysisEvent->getDuration();
        }

        $stopwatch?->start('doctrine_doctor.late_db_info', 'doctrine_doctor_profiling');
        $this->data['database_info'] = $databaseInfoCollector->collectDatabaseInfo($entityManager);
        $dbInfoEvent                 = $stopwatch?->stop('doctrine_doctor.late_db_info');

        if ($dbInfoEvent instanceof StopwatchEvent) {
            $this->data['profiler_overhead']['db_info_time_ms'] = $dbInfoEvent->getDuration();
        }

        $totalEvent = $stopwatch?->stop('doctrine_doctor.late_total');

        if ($totalEvent instanceof StopwatchEvent) {
            $this->data['profiler_overhead']['total_time_ms'] = $totalEvent->getDuration();
        }

        if (($this->data['show_debug_info'] ?? false) && isset($this->data['debug_data'])) {
            $this->data['debug_data']['query_time_stats']     = $queryStatsCalculator->calculateStats($this->data['timeline_queries']);
            $this->data['debug_data']['profiler_overhead_ms'] = $this->data['profiler_overhead']['total_time_ms'];
        }

        ServiceHolder::clear($token);

        unset($this->data['token']);
    }

    public function getName(): string
    {
        return 'doctrine_doctor';
    }

    /**
     * Reset all caches and cleanup ServiceHolder.
     */
    public function reset(): void
    {
        if (isset($this->data['token'])) {
            ServiceHolder::clear($this->data['token']);
        }

        SqlNormalizationCache::clear();
        CachedSqlStructureExtractor::clearCache();

        $this->data                 = [];
        $this->memoizedIssues       = null;
        $this->memoizedDatabaseInfo = null;
        $this->memoizedStats        = null;
        $this->memoizedDebugData    = null;
    }

    /**
     * Get all issues with memoization.
     *  Data already analyzed during collect() with generators
     *  Memoization: Objects reconstructed once, cached for subsequent calls
     * @return IssueInterface[]
     */
    public function getIssues(): array
    {
        if (null !== $this->memoizedIssues) {
            return $this->memoizedIssues;
        }

        if (!($this->data['enabled'] ?? false)) {
            $this->memoizedIssues = [];

            return [];
        }

        $issuesData = $this->data['issues'] ?? [];

        $issueReconstructor = new IssueReconstructor();

        $this->memoizedIssues = array_map(
            function ($issueData) use ($issueReconstructor) {
                return $issueReconstructor->reconstructIssue($issueData);
            },
            $issuesData,
        );

        return $this->memoizedIssues;
    }

    /**
     * Get issues by category with IssueCollection.
     *  OPTIMIZED: Uses IssueCollection for lazy filtering
     * @return IssueInterface[]
     */
    public function getIssuesByCategory(string $category): array
    {
        $issueCollection = IssueCollection::fromArray($this->getIssues());

        $filtered = $issueCollection->filter(function (IssueInterface $issue) use ($category): bool {
            if (!method_exists($issue, 'getCategory')) {
                return false;
            }

            return $issue->getCategory() === $category;
        });

        return $filtered->toArray();
    }

    /**
     * Get count of issues by category.
     */
    public function getIssueCountByCategory(string $category): int
    {
        return count($this->getIssuesByCategory($category));
    }

    /**
     * Get stats with memoization.
     *  OPTIMIZED: Uses IssueCollection methods (single pass instead of 3)
     */
    public function getStats(): array
    {
        if (null !== $this->memoizedStats) {
            return $this->memoizedStats;
        }

        $issueCollection = IssueCollection::fromArray($this->getIssues());
        $counts          = $issueCollection->statistics()->countBySeverity();

        $this->memoizedStats = [
            'total_issues' => $issueCollection->count(),
            'critical'     => $counts['critical'] ?? 0,
            'warning'      => $counts['warning'] ?? 0,
            'info'         => $counts['info'] ?? 0,
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

        Assert::isIterable($queries, '$queries must be iterable');

        foreach ($queries as $query) {
            yield $query;
        }
    }

    /**
     * Get timeline queries as array (for backward compatibility).
     * Use getTimelineQueries() for better memory efficiency.
     * @deprecated Use getTimelineQueries() generator for better performance
     */
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
            Assert::isArray($query, 'Query must be an array');

            $rawSql = $query['sql'] ?? '';
            $sql = is_string($rawSql) ? $rawSql : '';
            $executionTime = (float) ($query['executionMS'] ?? 0.0);

            if ($executionTime > 0 && $executionTime < 1) {
                $executionMs = $executionTime * 1000;
            } else {
                $executionMs = $executionTime;
            }

            if (!isset($grouped[$sql])) {
                $grouped[$sql] = [
                    'sql' => $sql,
                    'count' => 0,
                    'totalTimeMs' => 0.0,
                    'avgTimeMs' => 0.0,
                    'maxTimeMs' => 0.0,
                    'minTimeMs' => PHP_FLOAT_MAX,
                    'firstQuery' => $query, // Keep first occurrence for display
                ];
            }

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
     * Get debug data with memoization.
     *  Data already collected during collect().
     */
    public function getDebug(): array
    {
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
        return $this->data['profiler_overhead'] ?? [
            'analysis_time_ms' => 0,
            'db_info_time_ms'  => 0,
            'total_time_ms'    => 0,
        ];
    }

    /**
     * Analyze queries lazily (heavy processing - called ONLY when profiler is viewed).
     *  OPTIMIZED with generators for memory efficiency
     *  Only executed when getIssues() is called (profiler view)
     *  NOT executed during request handling
     *  Uses services from static cache (survives serialization)
     * @param iterable              $analyzers           Analyzers from static cache
     * @param DataCollectorLogger   $dataCollectorLogger Logger for conditional logging
     * @param IssueDeduplicator     $issueDeduplicator   Service to deduplicate redundant issues
     * @return array Array of issue data (not objects yet)
     */
    private function analyzeQueriesLazy(
        iterable $analyzers,
        DataCollectorLogger $dataCollectorLogger,
        IssueDeduplicator $issueDeduplicator,
    ): array {
        $queries = $this->data['timeline_queries'] ?? [];

        $dataCollectorLogger->logInfoIfEnabled(sprintf('analyzeQueriesLazy() called with %d queries', count($queries)));

        if ([] === $queries) {
            $dataCollectorLogger->logInfoIfEnabled('No queries found, but still running metadata analyzers!');
        }

        $sampleSize = min(3, count($queries));
        for ($i = 0; $i < $sampleSize; ++$i) {
            $sql = $queries[$i]['sql'] ?? 'N/A';
            $dataCollectorLogger->logInfoIfEnabled(sprintf('Query #%d: %s', $i + 1, substr($sql, 0, 100)));
        }

        $filteredQueries = $queries;
        if ([] !== $this->excludePaths) {
            $filteredQueries = $this->filterQueriesByPaths($queries, $this->excludePaths);
            $filteredCount = count($queries) - count($filteredQueries);
            $dataCollectorLogger->logInfoIfEnabled(sprintf(
                'Applied exclude_paths filter (%s): %d queries filtered, %d remaining',
                implode(', ', $this->excludePaths),
                $filteredCount,
                count($filteredQueries),
            ));
        }

        $createQueryDTOsGenerator = function () use ($filteredQueries, $dataCollectorLogger) {
            Assert::isIterable($filteredQueries, '$filteredQueries must be iterable');

            foreach ($filteredQueries as $query) {
                try {
                    yield QueryData::fromArray($query);
                } catch (\Throwable $e) {
                    $dataCollectorLogger->logWarningIfDebugEnabled('Failed to convert query to DTO', $e);

                }
            }
        };

        $allIssuesGenerator = function () use ($createQueryDTOsGenerator, $analyzers, $dataCollectorLogger) {
            Assert::isIterable($analyzers, '$analyzers must be iterable');

            foreach ($analyzers as $analyzer) {
                $analyzerName = $analyzer::class;
                $dataCollectorLogger->logInfoIfEnabled(sprintf('Running analyzer: %s', $analyzerName));

                try {
                    $queryCollection = QueryDataCollection::fromGenerator($createQueryDTOsGenerator);

                    $dataCollectorLogger->logInfoIfEnabled(sprintf('Created QueryCollection for %s', $analyzerName));

                    $issueCollection = $analyzer->analyze($queryCollection);

                    $issueCount = 0;

                    Assert::isIterable($issueCollection, '$issueCollection must be iterable');

                    foreach ($issueCollection as $issue) {
                        ++$issueCount;
                        $dataCollectorLogger->logInfoIfEnabled(sprintf('Issue #%d from %s: %s', $issueCount, $analyzerName, $issue->getTitle()));
                        yield $issue;
                    }

                    $dataCollectorLogger->logInfoIfEnabled(sprintf('Analyzer %s produced %d issues', $analyzerName, $issueCount));
                } catch (\Throwable $e) {
                    $dataCollectorLogger->logErrorIfDebugEnabled('Analyzer failed to execute: ' . $analyzer::class, $e);

                }
            }
        };

        $issuesCollection = IssueCollection::fromGenerator($allIssuesGenerator);

        $beforeCount = $issuesCollection->count();
        $dataCollectorLogger->logInfoIfEnabled(sprintf('Total issues before deduplication: %d', $beforeCount));

        $deduplicatedCollection = $issueDeduplicator->deduplicate($issuesCollection);

        $afterCount = $deduplicatedCollection->count();
        $removed = $beforeCount - $afterCount;
        $dataCollectorLogger->logInfoIfEnabled(sprintf(
            'Deduplication complete: %d issues removed, %d remaining',
            $removed,
            $afterCount,
        ));

        $deduplicatedCollection = $deduplicatedCollection->sorting()->bySeverityDescending();

        return $deduplicatedCollection->toArrayOfArrays();
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

        Assert::isIterable($queries, '$queries must be iterable');

        foreach ($queries as $query) {
            Assert::isArray($query, 'Query must be an array');

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

        Assert::isIterable($backtrace, '$backtrace must be iterable');

        $firstAppFrame = null;
        $hasValidFrames = false; // Track if we found at least one valid frame

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
