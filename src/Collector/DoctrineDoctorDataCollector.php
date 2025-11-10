<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
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
    // Memoization caches
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
        // Generate unique token for this request
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

        // FAST: Just copy raw query data (no processing)
        $queries = $this->doctrineDataCollector->getQueries();

        assert(is_iterable($queries), '$queries must be iterable');

        foreach ($queries as $query) {
            if (is_array($query)) {
                assert(is_iterable($query), '$query must be iterable');

                foreach ($query as $connectionQuery) {
                    $this->data['timeline_queries'][] = $connectionQuery;
                }
            }
        }

        // Store services in static holder for lateCollect() to access
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

        // Store basic debug info if enabled (very fast)
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
        // Get the token stored in collect()
        $token = $this->data['token'] ?? null;

        if (!$token) {
            return;
        }

        // Retrieve services from holder
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

        // Start measuring (this time is NOT counted in request metrics)
        $stopwatch?->start('doctrine_doctor.late_total', 'doctrine_doctor_profiling');

        // Run heavy analysis
        $stopwatch?->start('doctrine_doctor.late_analysis', 'doctrine_doctor_profiling');
        $this->data['issues'] = $this->analyzeQueriesLazy($analyzers, $dataCollectorLogger, $issueDeduplicator);
        $analysisEvent        = $stopwatch?->stop('doctrine_doctor.late_analysis');

        if ($analysisEvent instanceof StopwatchEvent) {
            $this->data['profiler_overhead']['analysis_time_ms'] = $analysisEvent->getDuration();
        }

        // Collect database info
        $stopwatch?->start('doctrine_doctor.late_db_info', 'doctrine_doctor_profiling');
        $this->data['database_info'] = $databaseInfoCollector->collectDatabaseInfo($entityManager);
        $dbInfoEvent                 = $stopwatch?->stop('doctrine_doctor.late_db_info');

        if ($dbInfoEvent instanceof StopwatchEvent) {
            $this->data['profiler_overhead']['db_info_time_ms'] = $dbInfoEvent->getDuration();
        }

        // Stop total measurement
        $totalEvent = $stopwatch?->stop('doctrine_doctor.late_total');

        if ($totalEvent instanceof StopwatchEvent) {
            $this->data['profiler_overhead']['total_time_ms'] = $totalEvent->getDuration();
        }

        // Update debug data if enabled
        if (($this->data['show_debug_info'] ?? false) && isset($this->data['debug_data'])) {
            $this->data['debug_data']['query_time_stats']     = $queryStatsCalculator->calculateStats($this->data['timeline_queries']);
            $this->data['debug_data']['profiler_overhead_ms'] = $this->data['profiler_overhead']['total_time_ms'];
        }

        // Clean up: remove services from static holder
        ServiceHolder::clear($token);

        // Remove token from data (not needed anymore)
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
        // Clean up services from holder if token exists
        if (isset($this->data['token'])) {
            ServiceHolder::clear($this->data['token']);
        }

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
        //  Return cached result if available
        if (null !== $this->memoizedIssues) {
            return $this->memoizedIssues;
        }

        if (!($this->data['enabled'] ?? false)) {
            $this->memoizedIssues = [];

            return [];
        }

        //  Reconstruct issue objects from stored data (fast, no re-analysis)
        $issuesData = $this->data['issues'] ?? [];

        // Reconstruct issues using a new IssueReconstructor
        // Note: templateRenderer is null here (lost after serialization)
        // But issues are already serialized with rendered content, so null is fine
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
        //  Use IssueCollection for efficient filtering
        $issueCollection = IssueCollection::fromArray($this->getIssues());

        // Filter by category using the issue's getCategory() method
        $filtered = $issueCollection->filter(function (IssueInterface $issue) use ($category): bool {
            // All issues should implement getCategory(), but check to be safe
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
        //  Return cached result if available
        if (null !== $this->memoizedStats) {
            return $this->memoizedStats;
        }

        //  Use IssueCollection for efficient counting (single pass)
        $issueCollection = IssueCollection::fromArray($this->getIssues());
        $counts          = $issueCollection->statistics()->countBySeverity();

        $this->memoizedStats = [
            'total_issues' => $issueCollection->count(),
            'critical'     => $counts['critical'] ?? 0,
            'warning'      => $counts['warning'] ?? 0,
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

        //  Generator: no memory copies
        assert(is_iterable($queries), '$queries must be iterable');

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
     * Get debug data with memoization.
     *  Data already collected during collect().
     */
    public function getDebug(): array
    {
        if (!($this->data['show_debug_info'] ?? false)) {
            return [];
        }

        //  Return cached result if available
        if (null !== $this->memoizedDebugData) {
            return $this->memoizedDebugData;
        }

        //  Return stored data (already collected during collect())
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
        //  Return cached result if available
        if (null !== $this->memoizedDatabaseInfo) {
            return $this->memoizedDatabaseInfo;
        }

        //  Return stored data (already collected during lateCollect())
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
            $dataCollectorLogger->logInfoIfEnabled('No queries to analyze!');

            return [];
        }

        // Log first few queries for debugging
        $sampleSize = min(3, count($queries));
        for ($i = 0; $i < $sampleSize; ++$i) {
            $sql = $queries[$i]['sql'] ?? 'N/A';
            $dataCollectorLogger->logInfoIfEnabled(sprintf('Query #%d: %s', $i + 1, substr($sql, 0, 100)));
        }

        //  OPTIMIZATION: Factory callable to create a fresh generator for each analyzer
        // Each analyzer gets its OWN generator - never reused!
        $createQueryDTOsGenerator = function () use ($queries, $dataCollectorLogger) {
            assert(is_iterable($queries), '$queries must be iterable');

            foreach ($queries as $query) {
                try {
                    yield QueryData::fromArray($query);
                } catch (\Throwable $e) {
                    // Log conversion errors but continue
                    $dataCollectorLogger->logWarningIfDebugEnabled('Failed to convert query to DTO', $e);

                    // Skip this query
                }
            }
        };

        //  OPTIMIZATION: Generator for issues - no array_merge, streams results
        // Each analyzer gets a FRESH QueryDataCollection (not shared!)
        $allIssuesGenerator = function () use ($createQueryDTOsGenerator, $analyzers, $dataCollectorLogger) {
            assert(is_iterable($analyzers), '$analyzers must be iterable');

            foreach ($analyzers as $analyzer) {
                $analyzerName = $analyzer::class;
                $dataCollectorLogger->logInfoIfEnabled(sprintf('Running analyzer: %s', $analyzerName));

                try {
                    //  Create FRESH collection for THIS analyzer only
                    // Pass the CALLABLE, not the generator result!
                    $queryCollection = QueryDataCollection::fromGenerator($createQueryDTOsGenerator);

                    $dataCollectorLogger->logInfoIfEnabled(sprintf('Created QueryCollection for %s', $analyzerName));

                    $issueCollection = $analyzer->analyze($queryCollection);

                    $issueCount = 0;

                    // Yield each issue individually
                    assert(is_iterable($issueCollection), '$issueCollection must be iterable');

                    foreach ($issueCollection as $issue) {
                        ++$issueCount;
                        $dataCollectorLogger->logInfoIfEnabled(sprintf('Issue #%d from %s: %s', $issueCount, $analyzerName, $issue->getTitle()));
                        yield $issue;
                    }

                    $dataCollectorLogger->logInfoIfEnabled(sprintf('Analyzer %s produced %d issues', $analyzerName, $issueCount));
                } catch (\Throwable $e) {
                    // Log analyzer errors - these are critical as they prevent issue detection!
                    $dataCollectorLogger->logErrorIfDebugEnabled('Analyzer failed to execute: ' . $analyzer::class, $e);

                    // Continue with next analyzer - don't let one broken analyzer stop all analysis
                }
            }
        };

        //  OPTIMIZATION: Use IssueCollection with built-in sorting
        $issuesCollection = IssueCollection::fromGenerator($allIssuesGenerator);

        // Log issue count before deduplication
        $beforeCount = $issuesCollection->count();
        $dataCollectorLogger->logInfoIfEnabled(sprintf('Total issues before deduplication: %d', $beforeCount));

        //  DEDUPLICATION: Remove redundant issues (N+1 vs Lazy Loading vs Frequent Query)
        $deduplicatedCollection = $issueDeduplicator->deduplicate($issuesCollection);

        // Log deduplication results
        $afterCount = $deduplicatedCollection->count();
        $removed = $beforeCount - $afterCount;
        $dataCollectorLogger->logInfoIfEnabled(sprintf(
            'Deduplication complete: %d issues removed, %d remaining',
            $removed,
            $afterCount,
        ));

        // Sort by severity after deduplication
        $deduplicatedCollection = $deduplicatedCollection->sorting()->bySeverityDescending();

        //  Final conversion to array (required for serialization)
        return $deduplicatedCollection->toArrayOfArrays();
    }
}
