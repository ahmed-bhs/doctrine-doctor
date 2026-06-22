<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\OrderByNullableLeadingColumnAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\TaskWithNullableDueDate;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\Test;

/**
 * Proves the analyzer flags an ORDER BY on a nullable leading column only
 * when the query is bounded (LIMIT), and stays silent on a full list where
 * NULL placement is cosmetic.
 */
final class OrderByNullableLeadingColumnAnalyzerIntegrationTest extends DatabaseTestCase
{
    private OrderByNullableLeadingColumnAnalyzer $orderByNullableLeadingColumnAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->orderByNullableLeadingColumnAnalyzer = new OrderByNullableLeadingColumnAnalyzer(
            connection: $this->connection,
        );

        $this->createSchema([TaskWithNullableDueDate::class]);
    }

    #[Test]
    public function it_flags_order_by_on_nullable_column_with_limit(): void
    {
        $task = new TaskWithNullableDueDate();
        $task->setTitle('Some task');
        $task->setDueDate(new \DateTimeImmutable('2026-01-10'));
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $queryDataCollection = QueryDataCollection::fromArray([
            new QueryData(
                sql: 'SELECT * FROM tasks_with_nullable_due_date ORDER BY dueDate ASC LIMIT 1',
                executionTime: QueryExecutionTime::fromMilliseconds(1),
                params: [],
                backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type
            ),
        ]);

        $issueCollection = $this->orderByNullableLeadingColumnAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);

        $issue = $issueCollection->toArray()[0];
        self::assertSame(Severity::INFO, $issue->getSeverity());
        self::assertStringContainsString('dueDate', $issue->getDescription());
    }

    #[Test]
    public function it_stays_silent_when_the_query_has_no_limit(): void
    {
        $task = new TaskWithNullableDueDate();
        $task->setTitle('Some task');
        $task->setDueDate(new \DateTimeImmutable('2026-01-10'));
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $queryDataCollection = QueryDataCollection::fromArray([
            new QueryData(
                sql: 'SELECT * FROM tasks_with_nullable_due_date ORDER BY dueDate ASC',
                executionTime: QueryExecutionTime::fromMilliseconds(1),
                params: [],
                backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type
            ),
        ]);

        $issueCollection = $this->orderByNullableLeadingColumnAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    #[Test]
    public function it_stays_silent_when_the_leading_column_is_not_nullable(): void
    {
        $task = new TaskWithNullableDueDate();
        $task->setTitle('Some task');
        $task->setDueDate(new \DateTimeImmutable('2026-01-10'));
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $queryDataCollection = QueryDataCollection::fromArray([
            new QueryData(
                sql: 'SELECT * FROM tasks_with_nullable_due_date ORDER BY title ASC LIMIT 1',
                executionTime: QueryExecutionTime::fromMilliseconds(1),
                params: [],
                backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type
            ),
        ]);

        $issueCollection = $this->orderByNullableLeadingColumnAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    #[Test]
    public function it_stays_silent_when_the_nullable_column_is_only_a_tiebreaker(): void
    {
        $task = new TaskWithNullableDueDate();
        $task->setTitle('Some task');
        $task->setDueDate(new \DateTimeImmutable('2026-01-10'));
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $queryDataCollection = QueryDataCollection::fromArray([
            new QueryData(
                sql: 'SELECT * FROM tasks_with_nullable_due_date ORDER BY title ASC, dueDate ASC LIMIT 1',
                executionTime: QueryExecutionTime::fromMilliseconds(1),
                params: [],
                backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type
            ),
        ]);

        $issueCollection = $this->orderByNullableLeadingColumnAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    #[Test]
    public function it_skips_non_select_queries(): void
    {
        $queryDataCollection = QueryDataCollection::fromArray([
            new QueryData(
                sql: 'UPDATE tasks_with_nullable_due_date SET title = "x" WHERE id = 1',
                executionTime: QueryExecutionTime::fromMilliseconds(1),
                params: [],
                backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type
            ),
        ]);

        $issueCollection = $this->orderByNullableLeadingColumnAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }
}
