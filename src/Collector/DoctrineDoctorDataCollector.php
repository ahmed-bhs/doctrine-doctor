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
use Symfony\Contracts\Service\ResetInterface;
use Webmozart\Assert\Assert;

/**
 * DataCollector for Doctrine Doctor.
 *
 * FrankenPHP Worker Mode Compatibility:
 * All analysis is performed in collect() (not lateCollect) because analyzers
 * use EntityManager which becomes invalid after the request ends in persistent
 * PHP runtimes (FrankenPHP, RoadRunner, Swoole).
 */
class DoctrineDoctorDataCollector extends DataCollector implements LateDataCollectorInterface, ResetInterface
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

    /**
     * @SuppressWarnings(UnusedFormalParameter)
     */
    public function collect(Request $_request, Response $_response, ?\Throwable $_exception = null): void
    {
        $databaseInfo = $this->dataCollectorHelpers->databaseInfoCollector->collectDatabaseInfo($this->entityManager);

        $this->data = [
            'enabled'           => (bool) $this->doctrineDataCollector,
            'show_debug_info'   => $this->showDebugInfo,
            'timeline_queries'  => [],
            'issues'            => [],
            'skipped_analyzers' => 0,
            'database_info'     => $databaseInfo,
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

        $this->runAnalysis();
    }

    public function lateCollect(): void
    {
    }

    private function runAnalysis(): void
    {
        $this->stopwatch?->start('doctrine_doctor.analysis', 'doctrine_doctor_profiling');

        SqlNormalizationCache::warmUp($this->data['timeline_queries']);
        CachedSqlStructureExtractor::warmUp($this->data['timeline_queries']);

        $this->data['issues'] = $this->analyzeQueriesLazy(
            $this->analyzers,
            $this->dataCollectorHelpers->dataCollectorLogger,
            $this->dataCollectorHelpers->issueDeduplicator,
        );

        $analysisEvent = $this->stopwatch?->stop('doctrine_doctor.analysis');

        if ($analysisEvent instanceof StopwatchEvent) {
            $this->data['profiler_overhead']['analysis_time_ms'] = $analysisEvent->getDuration();
            $this->data['profiler_overhead']['total_time_ms']    = $analysisEvent->getDuration();
        }

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
                'query_time_stats'          => $this->dataCollectorHelpers->queryStatsCalculator->calculateStats($this->data['timeline_queries']),
                'profiler_overhead_ms'      => $this->data['profiler_overhead']['total_time_ms'],
            ];
        }
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
    public function reset(): void
    {
        ServiceHolder::clearAll();

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

        $filteredQueries = $queries;
        if ([] !== $this->excludePaths) {
            $filteredQueries = $this->filterQueriesByPaths($queries, $this->excludePaths);
        }

        $createQueryDTOsGenerator = function () use ($filteredQueries, $dataCollectorLogger) {
            foreach ($filteredQueries as $query) {
                try {
                    yield QueryData::fromArray($query);
                } catch (\Throwable $e) {
                    $dataCollectorLogger->logWarningIfDebugEnabled('Failed to convert query to DTO', $e);
                }
            }
        };

        $memoryLimit = self::getMemoryLimitBytes();
        $allIssues = [];
        $originalLimit = ini_get('memory_limit');

        $safeLimit = $memoryLimit + (128 * 1048576);
        @ini_set('memory_limit', (string) $safeLimit);

        $memoryThreshold = (int) ($memoryLimit * 0.70);

        foreach ($analyzers as $analyzer) {
            if (memory_get_usage(true) >= $memoryThreshold) {
                ++$this->data['skipped_analyzers'];

                continue;
            }

            try {
                $queryCollection = QueryDataCollection::fromGenerator($createQueryDTOsGenerator);
                $issueCollection = $analyzer->analyze($queryCollection);

                foreach ($issueCollection as $issue) {
                    $allIssues[] = $issue;

                    if (memory_get_usage(true) >= $memoryThreshold) {
                        break;
                    }
                }

                unset($issueCollection, $queryCollection);
                gc_collect_cycles();
            } catch (\Throwable $e) {
                $dataCollectorLogger->logErrorIfDebugEnabled('Analyzer failed: ' . $analyzer::class, $e);
            }
        }

        @ini_set('memory_limit', $originalLimit ?: '512M');

        $issuesCollection = IssueCollection::fromArray($allIssues);
        unset($allIssues);

        $deduplicatedCollection = $issueDeduplicator->deduplicate($issuesCollection);
        unset($issuesCollection);

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
