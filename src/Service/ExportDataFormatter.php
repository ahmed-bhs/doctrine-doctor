<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Service;

use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Formats Doctrine Doctor analysis data into a JSON-serializable export structure.
 *
 * Works on plain issue objects and query arrays rather than the DataCollector
 * itself, so the export payload stays decoupled from the profiler integration.
 * Query entries are whitelisted field by field because the collector keeps the
 * raw first occurrence (backtrace, connection) alongside the timing metrics,
 * and those values are not JSON-encodable.
 */
final readonly class ExportDataFormatter
{
    /**
     * @param array<int, IssueInterface>                                                                                               $issues
     * @param array<string, mixed>                                                                                                     $stats
     * @param array<int, array{sql: string, count: int, totalTimeMs: float, avgTimeMs: float, maxTimeMs: float, minTimeMs: float}>      $queries
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
    public function format(array $issues, array $stats, array $queries): array
    {
        return [
            'created' => new DateTimeImmutable()->format(DateTimeInterface::ATOM),
            'issues' => $this->formatIssues($issues),
            'stats' => $stats,
            'queries' => $this->formatQueries($queries),
        ];
    }

    /**
     * @param array<int, IssueInterface> $issues
     *
     * @return array<int, array<string, mixed>>
     */
    public function formatIssues(array $issues): array
    {
        return array_values(array_map(
            static fn (IssueInterface $issue): array => $issue->toArray(),
            $issues,
        ));
    }

    /**
     * @param array<int, array{sql: string, count: int, totalTimeMs: float, avgTimeMs: float, maxTimeMs: float, minTimeMs: float}> $queries
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
        return array_values(array_map(
            static fn (array $query): array => [
                'sql' => $query['sql'],
                'count' => $query['count'],
                'totalTimeMs' => $query['totalTimeMs'],
                'avgTimeMs' => $query['avgTimeMs'],
                'maxTimeMs' => $query['maxTimeMs'],
                'minTimeMs' => $query['minTimeMs'],
            ],
            $queries,
        ));
    }
}
