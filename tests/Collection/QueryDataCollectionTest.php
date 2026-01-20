<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Collection;

use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use PHPUnit\Framework\TestCase;

final class QueryDataCollectionTest extends TestCase
{
    public function test_from_array(): void
    {
        $queries = [
            $this->createQueryData('SELECT * FROM users', 50.0),
            $this->createQueryData('SELECT * FROM posts', 150.0),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);

        self::assertCount(2, $queryDataCollection);
        self::assertFalse($queryDataCollection->isEmpty());
    }

    public function test_from_generator(): void
    {
        $queryDataCollection = QueryDataCollection::fromGenerator(function () {
            yield $this->createQueryData('SELECT * FROM users', 50.0);
            yield $this->createQueryData('SELECT * FROM posts', 150.0);
        });

        self::assertCount(2, $queryDataCollection);
    }

    public function test_empty(): void
    {
        $queryDataCollection = QueryDataCollection::empty();

        self::assertCount(0, $queryDataCollection);
        self::assertTrue($queryDataCollection->isEmpty());
        self::assertFalse($queryDataCollection->isNotEmpty());
    }

    public function test_filter_slow(): void
    {
        $queries = [
            $this->createQueryData('SELECT * FROM users', 50.0),
            $this->createQueryData('SELECT * FROM posts', 150.0),
            $this->createQueryData('SELECT * FROM comments', 200.0),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $slowQueries = $queryDataCollection->filterSlow(100.0);

        self::assertCount(2, $slowQueries);
    }

    public function test_filter_fast(): void
    {
        $queries = [
            $this->createQueryData('SELECT * FROM users', 50.0),
            $this->createQueryData('SELECT * FROM posts', 150.0),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $fastQueries = $queryDataCollection->filterFast(100.0);

        self::assertCount(1, $fastQueries);
    }

    public function test_only_selects(): void
    {
        $queries = [
            $this->createQueryData('SELECT * FROM users', 50.0),
            $this->createQueryData('INSERT INTO posts VALUES (1)', 30.0),
            $this->createQueryData('SELECT * FROM comments', 40.0),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $selectQueries = $queryDataCollection->onlySelects();

        self::assertCount(2, $selectQueries);
    }

    public function test_only_inserts(): void
    {
        $queries = [
            $this->createQueryData('SELECT * FROM users', 50.0),
            $this->createQueryData('INSERT INTO posts VALUES (1)', 30.0),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $insertQueries = $queryDataCollection->onlyInserts();

        self::assertCount(1, $insertQueries);
    }

    public function test_group_by_type(): void
    {
        $queries = [
            $this->createQueryData('SELECT * FROM users', 50.0),
            $this->createQueryData('INSERT INTO posts VALUES (1)', 30.0),
            $this->createQueryData('SELECT * FROM comments', 40.0),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $grouped = $queryDataCollection->groupByType();

        self::assertArrayHasKey('SELECT', $grouped);
        self::assertArrayHasKey('INSERT', $grouped);
        self::assertCount(2, $grouped['SELECT']);
        self::assertCount(1, $grouped['INSERT']);
    }

    public function test_total_execution_time(): void
    {
        $queries = [
            $this->createQueryData('SELECT * FROM users', 50.0),
            $this->createQueryData('SELECT * FROM posts', 150.0),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $total = $queryDataCollection->totalExecutionTime();

        self::assertEqualsWithDelta(200.0, $total, PHP_FLOAT_EPSILON);
    }

    public function test_average_execution_time(): void
    {
        $queries = [
            $this->createQueryData('SELECT * FROM users', 50.0),
            $this->createQueryData('SELECT * FROM posts', 150.0),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $average = $queryDataCollection->averageExecutionTime();

        self::assertEqualsWithDelta(100.0, $average, PHP_FLOAT_EPSILON);
    }

    public function test_slowest_query(): void
    {
        $queries = [
            $this->createQueryData('SELECT * FROM users', 50.0),
            $this->createQueryData('SELECT * FROM posts', 150.0),
            $this->createQueryData('SELECT * FROM comments', 100.0),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $slowest = $queryDataCollection->slowest();

        self::assertInstanceOf(QueryData::class, $slowest);
        self::assertEqualsWithDelta(150.0, $slowest->executionTime->inMilliseconds(), PHP_FLOAT_EPSILON);
    }

    public function test_fastest_query(): void
    {
        $queries = [
            $this->createQueryData('SELECT * FROM users', 50.0),
            $this->createQueryData('SELECT * FROM posts', 150.0),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $fastest = $queryDataCollection->fastest();

        self::assertInstanceOf(QueryData::class, $fastest);
        self::assertEqualsWithDelta(50.0, $fastest->executionTime->inMilliseconds(), PHP_FLOAT_EPSILON);
    }

    public function test_sort_by_execution_time(): void
    {
        $queries = [
            $this->createQueryData('SELECT * FROM users', 50.0),
            $this->createQueryData('SELECT * FROM posts', 150.0),
            $this->createQueryData('SELECT * FROM comments', 100.0),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $sorted = $queryDataCollection->sortByExecutionTime();

        $array = $sorted->toArray();
        self::assertEqualsWithDelta(150.0, $array[0]->executionTime->inMilliseconds(), PHP_FLOAT_EPSILON);
        self::assertEqualsWithDelta(100.0, $array[1]->executionTime->inMilliseconds(), PHP_FLOAT_EPSILON);
        self::assertEqualsWithDelta(50.0, $array[2]->executionTime->inMilliseconds(), PHP_FLOAT_EPSILON);
    }

    public function test_first_and_last(): void
    {
        $queries = [
            $this->createQueryData('SELECT * FROM users', 50.0),
            $this->createQueryData('SELECT * FROM posts', 150.0),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);

        self::assertEquals('SELECT * FROM users', $queryDataCollection->first()?->sql);
        self::assertEquals('SELECT * FROM posts', $queryDataCollection->last()?->sql);
    }

    public function test_count_by_type(): void
    {
        $queries = [
            $this->createQueryData('SELECT * FROM users', 50.0),
            $this->createQueryData('SELECT * FROM posts', 150.0),
            $this->createQueryData('INSERT INTO comments VALUES (1)', 30.0),
            $this->createQueryData('UPDATE users SET name = "test"', 40.0),
        ];

        $queryDataCollection = QueryDataCollection::fromArray($queries);
        $counts = $queryDataCollection->countByType();

        self::assertEquals(2, $counts['SELECT']);
        self::assertEquals(1, $counts['INSERT']);
        self::assertEquals(1, $counts['UPDATE']);
    }

    private function createQueryData(string $sql, float $executionMs): QueryData
    {
        return new QueryData(
            sql: $sql,
            executionTime: QueryExecutionTime::fromMilliseconds($executionMs),
        );
    }
}
