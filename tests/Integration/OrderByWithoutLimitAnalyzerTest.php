<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

use AhmedBhs\DoctrineDoctor\Analyzer\OrderByWithoutLimitAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use PHPUnit\Framework\TestCase;

final class OrderByWithoutLimitAnalyzerTest extends TestCase
{
    private OrderByWithoutLimitAnalyzer $orderByWithoutLimitAnalyzer;

    protected function setUp(): void
    {
        $inMemoryTemplateRenderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($inMemoryTemplateRenderer);
        $this->orderByWithoutLimitAnalyzer = new OrderByWithoutLimitAnalyzer($suggestionFactory);
    }

    public function test_detects_order_by_without_limit(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders ORDER BY created_at DESC',
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->orderByWithoutLimitAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('ORDER BY Without LIMIT Detected', $issue->getTitle());
        self::assertStringContainsString('ORDER BY without LIMIT', $issue->getDescription());
        self::assertStringContainsString('created_at DESC', $issue->getDescription());
    }

    public function test_ignores_order_by_with_limit(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders ORDER BY created_at DESC LIMIT 20',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->orderByWithoutLimitAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    public function test_ignores_order_by_with_offset(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders ORDER BY created_at DESC OFFSET 20',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->orderByWithoutLimitAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    public function test_ignores_order_by_with_limit_and_offset(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders ORDER BY created_at DESC LIMIT 20 OFFSET 40',
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->orderByWithoutLimitAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    public function test_detects_multiple_order_by_columns(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders ORDER BY status ASC, created_at DESC',
            executionTime: QueryExecutionTime::fromMilliseconds(150.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->orderByWithoutLimitAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertStringContainsString('status ASC, created_at DESC', $issue->getDescription());
    }

    public function test_sets_critical_severity_for_slow_queries(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders ORDER BY created_at DESC',
            executionTime: QueryExecutionTime::fromMilliseconds(600.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->orderByWithoutLimitAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('critical', $issue->getSeverity()->value);
    }

    public function test_sets_warning_severity_for_moderate_queries(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders ORDER BY created_at DESC',
            executionTime: QueryExecutionTime::fromMilliseconds(200.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->orderByWithoutLimitAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('warning', $issue->getSeverity()->value);
    }

    public function test_sets_info_severity_for_fast_queries(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders ORDER BY created_at DESC',
            executionTime: QueryExecutionTime::fromMilliseconds(50.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->orderByWithoutLimitAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('info', $issue->getSeverity()->value);
    }

    public function test_deduplicates_same_order_by_clause(): void
    {
        $query1 = new QueryData(
            sql: 'SELECT * FROM orders WHERE status = "pending" ORDER BY created_at DESC',
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
        );

        $query2 = new QueryData(
            sql: 'SELECT * FROM orders WHERE status = "completed" ORDER BY created_at DESC',
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$query1, $query2]);
        $issueCollection = $this->orderByWithoutLimitAnalyzer->analyze($queryDataCollection);

        // Should deduplicate based on ORDER BY clause
        self::assertCount(1, $issueCollection);
    }

    public function test_handles_array_format_query(): void
    {
        $query = new QueryData(
            sql: 'SELECT * FROM orders ORDER BY created_at DESC',
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
            backtrace: null,
        );

        $queryDataCollection = QueryDataCollection::fromArray([$query]);
        $issueCollection = $this->orderByWithoutLimitAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
    }

    public function test_handles_case_insensitive_order_by(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders order by created_at desc',
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->orderByWithoutLimitAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
    }

    public function test_ignores_queries_without_order_by(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders WHERE status = "pending"',
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->orderByWithoutLimitAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }
}
