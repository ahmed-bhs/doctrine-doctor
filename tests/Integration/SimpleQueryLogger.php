<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;

/**
 * Simple query logger using Doctrine's DebugStack.
 */
class SimpleQueryLogger
{
    /** @var array<array{sql: string, params: mixed|null, time: float}> */
    private array $queries = [];

    private bool $enabled = false;

    /**
     * Start collecting queries.
     */
    public function start(): void
    {
        $this->enabled = true;
    }

    /**
     * Stop collecting queries.
     */
    public function stop(): void
    {
        $this->enabled = false;
    }

    /**
     * Reset collected queries.
     */
    public function reset(): void
    {
        $this->queries = [];
    }

    /**
     * Log a query (short alias for middleware).
     */
    public function log(string $sql): void
    {
        if (!$this->enabled) {
            return;
        }

        // Capture backtrace to help analyzers detect patterns
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);

        $this->queries[] = [
            'sql' => $sql,
            'params' => null,
            'time' => 1.0, // 1ms default
            'backtrace' => $backtrace,
        ];
    }

    /**
     * Log a query with full details.
     */
    public function logQuery(string $sql, ?array $params = null, float $executionTime = 0.001): void
    {
        if (!$this->enabled) {
            return;
        }

        // Capture backtrace if not provided
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);

        $this->queries[] = [
            'sql' => $sql,
            'params' => $params,
            'time' => $executionTime * 1000, // Convert to milliseconds
            'backtrace' => $backtrace,
        ];
    }

    /**
     * Get all collected queries as QueryDataCollection.
     */
    public function getQueries(): QueryDataCollection
    {
        $queryDataArray = array_map(
            fn (array $query): QueryData => new QueryData(
                sql: $query['sql'],
                executionTime: QueryExecutionTime::fromMilliseconds($query['time']),
                params: $query['params'] ?? [],
                backtrace: $query['backtrace'] ?? null, // @phpstan-ignore-line isset.offset
            ),
            $this->queries,
        );

        return QueryDataCollection::fromArray($queryDataArray);
    }

    /**
     * Get raw query data.
     *
     * @return array<array{sql: string, params: mixed|null, time: float}>
     */
    public function getRawQueries(): array
    {
        return $this->queries;
    }

    /**
     * Get the number of queries executed.
     */
    public function count(): int
    {
        return count($this->queries);
    }
}
