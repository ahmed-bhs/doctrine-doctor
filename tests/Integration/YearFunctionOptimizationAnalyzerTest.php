<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\YearFunctionOptimizationAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use PHPUnit\Framework\TestCase;

final class YearFunctionOptimizationAnalyzerTest extends TestCase
{
    private YearFunctionOptimizationAnalyzer $yearFunctionOptimizationAnalyzer;

    protected function setUp(): void
    {
        $inMemoryTemplateRenderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($inMemoryTemplateRenderer);
        $this->yearFunctionOptimizationAnalyzer = new YearFunctionOptimizationAnalyzer($suggestionFactory);
    }

    public function test_detects_year_function(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders WHERE YEAR(created_at) = 2023',
            executionTime: QueryExecutionTime::fromMilliseconds(150.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->yearFunctionOptimizationAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('YEAR() Function Prevents Index Usage', $issue->getTitle());
        self::assertStringContainsString('YEAR(created_at)', $issue->getDescription());
        self::assertStringContainsString('BETWEEN', $issue->getDescription());
    }

    public function test_detects_month_function(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders WHERE MONTH(created_at) = 12',
            executionTime: QueryExecutionTime::fromMilliseconds(80.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->yearFunctionOptimizationAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('MONTH() Function Prevents Index Usage', $issue->getTitle());
        self::assertStringContainsString('MONTH(created_at)', $issue->getDescription());
    }

    public function test_detects_date_function(): void
    {
        $queryData = new QueryData(
            sql: "SELECT * FROM orders WHERE DATE(created_at) = '2023-01-15'",
            executionTime: QueryExecutionTime::fromMilliseconds(120.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->yearFunctionOptimizationAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('DATE() Function Prevents Index Usage', $issue->getTitle());
        self::assertStringContainsString('DATE(created_at)', $issue->getDescription());
    }

    public function test_detects_hour_function(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM logs WHERE HOUR(created_at) = 14',
            executionTime: QueryExecutionTime::fromMilliseconds(90.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->yearFunctionOptimizationAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('HOUR() Function Prevents Index Usage', $issue->getTitle());
    }

    public function test_detects_year_function_with_field_alias(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders o WHERE YEAR(o.created_at) = 2023',
            executionTime: QueryExecutionTime::fromMilliseconds(130.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->yearFunctionOptimizationAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertStringContainsString('YEAR(o.created_at)', $issue->getDescription());
    }

    public function test_ignores_correct_between_syntax(): void
    {
        $queryData = new QueryData(
            sql: "SELECT * FROM orders WHERE created_at BETWEEN '2023-01-01' AND '2023-12-31'",
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->yearFunctionOptimizationAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    public function test_ignores_correct_range_comparison(): void
    {
        $queryData = new QueryData(
            sql: "SELECT * FROM orders WHERE created_at >= '2023-01-01' AND created_at < '2024-01-01'",
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->yearFunctionOptimizationAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    public function test_detects_multiple_date_functions(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders WHERE YEAR(created_at) = 2023 AND MONTH(updated_at) = 12',
            executionTime: QueryExecutionTime::fromMilliseconds(200.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->yearFunctionOptimizationAnalyzer->analyze($queryDataCollection);

        self::assertCount(2, $issueCollection);
    }

    public function test_detects_year_function_with_greater_than(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders WHERE YEAR(created_at) >= 2023',
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->yearFunctionOptimizationAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertStringContainsString('YEAR(created_at)', $issue->getDescription());
    }

    public function test_sets_critical_severity_for_slow_queries(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders WHERE YEAR(created_at) = 2023',
            executionTime: QueryExecutionTime::fromMilliseconds(150.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->yearFunctionOptimizationAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('critical', $issue->getSeverity()->value);
    }

    public function test_sets_warning_severity_for_fast_queries(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders WHERE YEAR(created_at) = 2023',
            executionTime: QueryExecutionTime::fromMilliseconds(50.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->yearFunctionOptimizationAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('warning', $issue->getSeverity()->value);
    }

    public function test_deduplicates_same_date_function(): void
    {
        $query1 = new QueryData(
            sql: 'SELECT * FROM orders WHERE YEAR(created_at) = 2023',
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
        );

        $query2 = new QueryData(
            sql: 'SELECT * FROM orders WHERE YEAR(created_at) = 2023',
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$query1, $query2]);
        $issueCollection = $this->yearFunctionOptimizationAnalyzer->analyze($queryDataCollection);

        // Should deduplicate - same function usage
        self::assertCount(1, $issueCollection);
    }

    public function test_handles_array_format_query(): void
    {
        $query = new QueryData(
            sql: 'SELECT * FROM orders WHERE YEAR(created_at) = 2023',
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
            backtrace: null,
        );

        $queryDataCollection = QueryDataCollection::fromArray([$query]);
        $issueCollection = $this->yearFunctionOptimizationAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
    }

    public function test_handles_case_insensitive_functions(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders WHERE year(created_at) = 2023',
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->yearFunctionOptimizationAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
    }

    public function test_detects_day_function(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM orders WHERE DAY(created_at) = 15',
            executionTime: QueryExecutionTime::fromMilliseconds(80.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->yearFunctionOptimizationAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('DAY() Function Prevents Index Usage', $issue->getTitle());
    }

    public function test_detects_minute_function(): void
    {
        $queryData = new QueryData(
            sql: 'SELECT * FROM logs WHERE MINUTE(created_at) = 30',
            executionTime: QueryExecutionTime::fromMilliseconds(70.0),
            params: [],
        );

        $queryDataCollection = QueryDataCollection::fromArray([$queryData]);
        $issueCollection = $this->yearFunctionOptimizationAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);
        $issue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $issue);
        self::assertSame('MINUTE() Function Prevents Index Usage', $issue->getTitle());
    }
}
