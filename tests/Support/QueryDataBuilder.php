<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Support;

use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;

/**
 * Builder pattern for creating test query data.
 * Simplifies test setup by providing fluent API for query data creation.
 *
 * Example:
 *   $queries = QueryDataBuilder::create()
 *       ->addQuery('SELECT revenue / quantity FROM sales')
 *       ->addQueryWithBacktrace('SELECT * FROM users', [
 *           ['file' => 'UserRepository.php', 'line' => 42]
 *       ])
 *       ->build();
 */
class QueryDataBuilder
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $queries = [];

    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * Add a simple query with SQL only.
     */
    public function addQuery(string $sql, ?float $executionTime = null, ?int $executionCount = null): self
    {
        $this->queries[] = [
            'sql' => $sql,
            'executionMS' => $executionTime ?? 0.0,  // Changed from executionTimeMS to executionMS
            'executionCount' => $executionCount ?? 1,
        ];

        return $this;
    }

    /**
     * Add a query with backtrace information.
     *
     * @param array<int, array<string, mixed>>|null $backtrace
     */
    public function addQueryWithBacktrace(
        string $sql,
        ?array $backtrace = null,
        ?float $executionTime = null,
        ?int $executionCount = null,
    ): self {
        $this->queries[] = [
            'sql' => $sql,
            'backtrace' => $backtrace ?? [],
            'executionMS' => $executionTime ?? 0.0,  // Changed from executionTimeMS to executionMS
            'executionCount' => $executionCount ?? 1,
        ];

        return $this;
    }

    /**
     * Add a slow query (execution time > 1000ms).
     */
    public function addSlowQuery(string $sql, float $executionTime = 1500.0): self
    {
        return $this->addQuery($sql, $executionTime);
    }

    /**
     * Add a frequently executed query.
     */
    public function addFrequentQuery(string $sql, int $executionCount = 100): self
    {
        return $this->addQuery($sql, 10.0, $executionCount);
    }

    /**
     * Add multiple queries at once.
     *
     * @param array<int, string> $queries
     */
    public function addQueries(array $queries): self
    {
        foreach ($queries as $sql) {
            $this->addQuery($sql);
        }

        return $this;
    }

    /**
     * Build the QueryDataCollection.
     */
    public function build(): QueryDataCollection
    {
        // Convert array data to QueryData objects
        $queryDataObjects = array_map(
            fn (array $queryData) => \AhmedBhs\DoctrineDoctor\DTO\QueryData::fromArray($queryData),
            $this->queries,
        );

        return QueryDataCollection::fromArray($queryDataObjects);
    }

    /**
     * Get raw queries array (useful for debugging).
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->queries;
    }
}
