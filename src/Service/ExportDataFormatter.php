<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Service;

use AhmedBhs\DoctrineDoctor\Collector\DoctrineDoctorDataCollector;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Formats Doctrine Doctor collector data into a JSON-serializable export structure.
 *
 * Extracts issues, statistics, and query metrics from a DataCollector instance
 * and prepares them for JSON export with proper field filtering to avoid
 * serialization issues (e.g., backtrace objects, connection instances).
 */
final readonly class ExportDataFormatter
{
    /**
     * Format complete export payload with issues, stats, and queries.
     *
     * @return array{
     *  created: string,
     *  issues: array<int, array<string, mixed>>,
     *  stats: array<string, mixed>,
     *  queries: array<int, array{
     *     sql: string,
     *     count: int,
     *     totalTimeMs: float,
     *     avgTimeMs: float,
     *     maxTimeMs: float,
     *     minTimeMs: float,
     *  }>
     * }
     */
    public function format(DoctrineDoctorDataCollector $collector): array
    {
        return [
            'created' => new DateTimeImmutable()->format(DateTimeInterface::ATOM),
            'issues' => $this->formatIssues($collector->getIssues()),
            'stats' => $collector->getStats(),
            'queries' => $this->formatQueries($collector->getGroupedQueriesByTime()),
        ];
    }

    /**
     * Extract issue information from collector issues.
     *
     * Calls toArray() on each issue object to extract serializable data.
     *
     * @param array<int, IssueInterface> $issues
     *
     * @return array<int, array<string, mixed>>
     */
    public function formatIssues(array $issues): array
    {
        return array_map(
            static fn (IssueInterface $issue) => $issue->toArray(),
            $issues,
        );
    }

    /**
     * Extract query information from grouped queries.
     *
     * Filters out non-serializable data (backtrace, connection) and extracts
     * only the fields needed for the export (sql, count, timing metrics).
     *
     * @param array<int, array{sql: string, count: int, totalTimeMs: float, avgTimeMs: float, maxTimeMs: float, minTimeMs: float, ...}> $queries
     *
     * @return array<int, array{
     *     sql: string,
     *     count: int,
     *     totalTimeMs: float,
     *     avgTimeMs: float,
     *     maxTimeMs: float,
     *     minTimeMs: float,
     * }>
     */
    public function formatQueries(array $queries): array
    {
        return array_map(
            static function (array $query): array {
                return [
                    'sql' => $query['sql'],
                    'count' => $query['count'],
                    'totalTimeMs' => $query['totalTimeMs'],
                    'avgTimeMs' => $query['avgTimeMs'],
                    'maxTimeMs' => $query['maxTimeMs'],
                    'minTimeMs' => $query['minTimeMs'],
                    // Note: we omit backtrace and connection here because they can't be serialized without errors.
                ];
            },
            $queries,
        );
    }
}
