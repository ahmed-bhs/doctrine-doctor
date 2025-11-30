<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collection;

use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use Webmozart\Assert\Assert;

/**
 * Type-safe collection for QueryData objects.
 * Provides query-specific business logic and filtering.
 * @extends AbstractCollection<QueryData>
 */
final class QueryDataCollection extends AbstractCollection
{
    /**
     * Filter queries by type (SELECT, INSERT, UPDATE, DELETE).
     */
    public function filterByType(string $type): self
    {
        return $this->filter(fn (QueryData $queryData): bool => $queryData->getQueryType() === strtoupper($type));
    }

    /**
     * Get only SELECT queries.
     */
    public function onlySelects(): self
    {
        return $this->filter(fn (QueryData $queryData): bool => $queryData->isSelect());
    }

    /**
     * Get only INSERT queries.
     */
    public function onlyInserts(): self
    {
        return $this->filter(fn (QueryData $queryData): bool => $queryData->isInsert());
    }

    /**
     * Get only UPDATE queries.
     */
    public function onlyUpdates(): self
    {
        return $this->filter(fn (QueryData $queryData): bool => $queryData->isUpdate());
    }

    /**
     * Get only DELETE queries.
     */
    public function onlyDeletes(): self
    {
        return $this->filter(fn (QueryData $queryData): bool => $queryData->isDelete());
    }

    /**
     * Filter slow queries above threshold.
     */
    public function filterSlow(float $thresholdMs = 100.0): self
    {
        return $this->filter(fn (QueryData $queryData): bool => $queryData->isSlow($thresholdMs));
    }

    /**
     * Filter fast queries below threshold.
     */
    public function filterFast(float $thresholdMs = 100.0): self
    {
        return $this->filter(fn (QueryData $queryData): bool => !$queryData->isSlow($thresholdMs));
    }

    /**
     * Filter queries with backtrace.
     */
    public function withBacktrace(): self
    {
        return $this->filter(fn (QueryData $queryData): bool => null !== $queryData->backtrace);
    }

    /**
     * Filter queries without backtrace.
     */
    public function withoutBacktrace(): self
    {
        return $this->filter(fn (QueryData $queryData): bool => null === $queryData->backtrace);
    }

    /**
     * Filter queries matching SQL pattern.
     */
    public function matchingSql(string $pattern): self
    {
        return $this->filter(fn (QueryData $queryData): bool => false !== stripos($queryData->sql, $pattern));
    }

    /**
     * Group queries by normalized SQL pattern.
     * Useful for detecting N+1 queries.
     * @param callable(string): string $normalizer Function to normalize SQL (remove specific values)
     * @return array<string, self>
     */
    public function groupByPattern(callable $normalizer): array
    {
        return $this->groupBy(fn (QueryData $queryData) => $normalizer($queryData->sql));
    }

    /**
     * Group queries by query type (SELECT, INSERT, etc).
     * @return array<string, self>
     */
    public function groupByType(): array
    {
        return $this->groupBy(fn (QueryData $queryData): string => $queryData->getQueryType());
    }

    /**
     * Calculate total execution time.
     */
    public function totalExecutionTime(): float
    {
        $total = 0.0;

        Assert::isIterable($this, '$this must be iterable');

        foreach ($this as $query) {
            $total += $query->executionTime->inMilliseconds();
        }

        return $total;
    }

    /**
     * Calculate average execution time.
     */
    public function averageExecutionTime(): float
    {
        $count = $this->count();

        if (0 === $count) {
            return 0.0;
        }

        return $this->totalExecutionTime() / $count;
    }

    /**
     * Get slowest query.
     */
    public function slowest(): ?QueryData
    {
        $slowest = null;
        $maxTime = 0.0;

        Assert::isIterable($this, '$this must be iterable');

        foreach ($this as $query) {
            $time = $query->executionTime->inMilliseconds();

            if ($time > $maxTime) {
                $maxTime = $time;
                $slowest = $query;
            }
        }

        return $slowest;
    }

    /**
     * Get fastest query.
     */
    public function fastest(): ?QueryData
    {
        $fastest = null;
        $minTime = PHP_FLOAT_MAX;

        Assert::isIterable($this, '$this must be iterable');

        foreach ($this as $query) {
            $time = $query->executionTime->inMilliseconds();

            if ($time < $minTime) {
                $minTime = $time;
                $fastest = $query;
            }
        }

        return $fastest;
    }

    /**
     * Sort by execution time (slowest first).
     */
    public function sortByExecutionTimeDescending(): self
    {
        $items = $this->toArray();
        usort(
            $items,
            fn (QueryData $queryA, QueryData $queryB): int =>
            $queryB->executionTime->inMilliseconds() <=> $queryA->executionTime->inMilliseconds(),
        );

        return self::fromArray($items);
    }

    /**
     * Sort by execution time (fastest first).
     */
    public function sortByExecutionTimeAscending(): self
    {
        $items = $this->toArray();
        usort(
            $items,
            fn (QueryData $queryA, QueryData $queryB): int =>
            $queryA->executionTime->inMilliseconds() <=> $queryB->executionTime->inMilliseconds(),
        );

        return self::fromArray($items);
    }

    /**
     * Sort by execution time (slowest first).
     * @deprecated Use sortByExecutionTimeDescending() or sortByExecutionTimeAscending() instead
     */
    public function sortByExecutionTime(): self
    {
        return $this->sortByExecutionTimeDescending();
    }

    /**
     * Count queries by type.
     * @return array<string, int>
     */
    public function countByType(): array
    {
        $counts = [
            'SELECT' => 0,
            'INSERT' => 0,
            'UPDATE' => 0,
            'DELETE' => 0,
            'OTHER'  => 0,
        ];

        Assert::isIterable($this, '$this must be iterable');

        foreach ($this as $query) {
            $type          = $query->getQueryType();
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Get queries with row count above threshold.
     */
    public function withRowCountAbove(int $threshold): self
    {
        return $this->filter(fn (QueryData $queryData): bool => null !== $queryData->rowCount && $queryData->rowCount > $threshold);
    }

    /**
     * Extract all SQL queries as array of strings.
     * @return array<int, string>
     */
    public function getSqlQueries(): array
    {
        return $this->map(fn (QueryData $queryData): string => $queryData->sql);
    }

    /**
     * Exclude queries that originate from specified paths (e.g., vendor/, var/cache/).
     * Analyzes the backtrace to determine if any frame is from excluded paths.
     * This helps focus analysis on application code rather than third-party libraries.
     *
     * @param array<string> $excludedPaths Paths to exclude (e.g., ['vendor/', 'var/cache/'])
     */
    public function excludePaths(array $excludedPaths): self
    {
        if ([] === $excludedPaths) {
            return $this;
        }

        return $this->filter(fn (QueryData $queryData): bool => !$this->isQueryFromExcludedPaths($queryData, $excludedPaths));
    }

    /**
     * Check if a query originates from one of the excluded paths by analyzing its backtrace.
     * If any frame in the backtrace contains an excluded path, the query is filtered out.
     *
     * @param array<string> $excludedPaths Paths to check against (e.g., ['vendor/', 'var/cache/'])
     */
    private function isQueryFromExcludedPaths(QueryData $queryData, array $excludedPaths): bool
    {
        $backtrace = $queryData->backtrace;

        if (null === $backtrace || [] === $backtrace) {
            return false;
        }

        foreach ($backtrace as $frame) {
            $file = $frame['file'] ?? '';

            if ('' === $file || !is_string($file)) {
                continue;
            }

            $normalizedPath = str_replace('\\', '/', $file);

            foreach ($excludedPaths as $excludedPath) {
                $normalizedExcludedPath = str_replace('\\', '/', $excludedPath);

                if (str_contains($normalizedPath, $normalizedExcludedPath)) {
                    return true; // Found a match, exclude this query
                }
            }
        }

        return false;
    }

    /**
     * @param iterable<int, QueryData> $items
     */
    protected static function createInstance(iterable $items): static
    {
        return new self($items);
    }
}
