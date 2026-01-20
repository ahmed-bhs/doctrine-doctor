<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\YearFunctionOptimizationAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for YearFunctionOptimizationAnalyzer.
 *
 * This analyzer detects date/time functions used on indexed columns that prevent index usage.
 * Functions like YEAR(), MONTH(), DAY() prevent the database from using indexes on date columns.
 */
final class YearFunctionOptimizationAnalyzerTest extends TestCase
{
    private YearFunctionOptimizationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new YearFunctionOptimizationAnalyzer(
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_no_queries(): void
    {
        // Arrange: No queries
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_returns_empty_collection_for_optimized_queries(): void
    {
        // Arrange: Queries using BETWEEN and range comparisons (optimized)
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM orders WHERE created_at BETWEEN '2023-01-01' AND '2023-12-31'")
            ->addQuery("SELECT * FROM orders WHERE created_at >= '2023-01-01' AND created_at < '2024-01-01'")
            ->addQuery("SELECT * FROM orders WHERE created_at > '2023-01-01'")
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_year_function_with_equals(): void
    {
        // Arrange: YEAR() with = operator
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE YEAR(created_at) = 2023', 150.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertEquals('YEAR() Function Prevents Index Usage', $issue->getTitle());
        self::assertStringContainsString('YEAR(created_at)', $issue->getDescription());
        self::assertStringContainsString('BETWEEN', $issue->getDescription());
        self::assertEquals('performance', $issue->getCategory());
    }

    #[Test]
    public function it_detects_month_function(): void
    {
        // Arrange: MONTH() function
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE MONTH(created_at) = 12', 80.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertEquals('MONTH() Function Prevents Index Usage', $issue->getTitle());
        self::assertStringContainsString('MONTH(created_at)', $issue->getDescription());
    }

    #[Test]
    public function it_detects_day_function(): void
    {
        // Arrange: DAY() function
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE DAY(created_at) = 15', 80.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertEquals('DAY() Function Prevents Index Usage', $issue->getTitle());
    }

    #[Test]
    public function it_detects_date_function(): void
    {
        // Arrange: DATE() function
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM orders WHERE DATE(created_at) = '2023-01-15'", 120.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertEquals('DATE() Function Prevents Index Usage', $issue->getTitle());
        self::assertStringContainsString('DATE(created_at)', $issue->getDescription());
    }

    #[Test]
    public function it_detects_hour_function(): void
    {
        // Arrange: HOUR() function
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM logs WHERE HOUR(created_at) = 14', 90.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertEquals('HOUR() Function Prevents Index Usage', $issue->getTitle());
    }

    #[Test]
    public function it_detects_minute_function(): void
    {
        // Arrange: MINUTE() function
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM logs WHERE MINUTE(created_at) = 30', 70.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertEquals('MINUTE() Function Prevents Index Usage', $issue->getTitle());
    }

    #[Test]
    public function it_detects_year_function_with_table_alias(): void
    {
        // Arrange: Field with table alias (o.created_at)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders o WHERE YEAR(o.created_at) = 2023', 130.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertStringContainsString('YEAR(o.created_at)', $issue->getDescription());
    }

    #[Test]
    public function it_detects_multiple_date_functions_in_single_query(): void
    {
        // Arrange: Query with multiple date functions
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE YEAR(created_at) = 2023 AND MONTH(updated_at) = 12', 200.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect both functions
        self::assertCount(2, $issues);
    }

    #[Test]
    public function it_deduplicates_same_date_function_across_queries(): void
    {
        // Arrange: Same YEAR() function used multiple times
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE YEAR(created_at) = 2023', 100.0)
            ->addQuery('SELECT * FROM orders WHERE YEAR(created_at) = 2023', 100.0)
            ->addQuery('SELECT * FROM invoices WHERE YEAR(created_at) = 2023', 100.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should deduplicate based on function+field+operator+value
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_detects_different_years_as_separate_issues(): void
    {
        // Arrange: Different year values
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE YEAR(created_at) = 2023', 100.0)
            ->addQuery('SELECT * FROM orders WHERE YEAR(created_at) = 2024', 100.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Two different values = 2 issues
        self::assertCount(2, $issues);
    }

    #[Test]
    public function it_sets_severity_to_critical_for_slow_queries(): void
    {
        // Arrange: Query taking > 100ms
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE YEAR(created_at) = 2023', 150.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertEquals('critical', $issue->getSeverity()->value);
        self::assertStringContainsString('150.00ms', $issue->getDescription());
    }

    #[Test]
    public function it_sets_severity_to_warning_for_fast_queries(): void
    {
        // Arrange: Query taking <= 100ms
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE YEAR(created_at) = 2023', 50.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertEquals('warning', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_handles_case_insensitive_functions(): void
    {
        // Arrange: lowercase function names
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE year(created_at) = 2023', 100.0)
            ->addQuery('SELECT * FROM orders WHERE month(created_at) = 12', 100.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(2, $issues);
    }

    #[Test]
    public function it_handles_backtrace_information(): void
    {
        // Arrange: Query with backtrace
        $backtrace = [
            ['file' => 'OrderRepository.php', 'line' => 123, 'class' => 'OrderRepository', 'function' => 'findByYear'],
        ];
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT * FROM orders WHERE YEAR(created_at) = 2023',
                $backtrace,
                100.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertNotNull($issue->getBacktrace());
        self::assertEquals($backtrace, $issue->getBacktrace());
    }

    #[Test]
    public function it_detects_year_function_with_greater_than(): void
    {
        // Arrange: YEAR() with >= operator
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE YEAR(created_at) >= 2023', 100.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertStringContainsString('YEAR(created_at)', $issue->getDescription());
    }

    #[Test]
    public function it_detects_year_function_with_less_than(): void
    {
        // Arrange: YEAR() with < operator
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE YEAR(created_at) < 2023', 100.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_detects_year_function_with_not_equals(): void
    {
        // Arrange: YEAR() with != operator
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE YEAR(created_at) != 2023', 100.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_provides_suggestion_with_optimized_clause(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE YEAR(created_at) = 2023', 100.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();
        self::assertNotNull($suggestion);

        // Verify suggestion context
        /** @var \AhmedBhs\DoctrineDoctor\Suggestion\ModernSuggestion $suggestion */
        self::assertEquals('Performance/date_function_optimization', $suggestion->getTemplateName());
        /** @var \AhmedBhs\DoctrineDoctor\Suggestion\ModernSuggestion $suggestion */
        $context = $suggestion->getContext();
        self::assertEquals('YEAR', $context['function']);
        self::assertEquals('created_at', $context['field']);
        self::assertStringContainsString('BETWEEN', $context['optimized_clause']);
        self::assertStringContainsString('2023-01-01', $context['optimized_clause']);
        self::assertStringContainsString('2023-12-31', $context['optimized_clause']);
    }

    #[Test]
    public function it_provides_optimized_clause_for_date_function(): void
    {
        // Arrange: DATE() function
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM orders WHERE DATE(created_at) = '2023-01-15'", 100.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();
        self::assertNotNull($suggestion);
        /** @var \AhmedBhs\DoctrineDoctor\Suggestion\ModernSuggestion $suggestion */
        $context = $suggestion->getContext();

        // For DATE() with =, should suggest BETWEEN with time range
        self::assertStringContainsString('BETWEEN', $context['optimized_clause']);
        self::assertStringContainsString('00:00:00', $context['optimized_clause']);
        self::assertStringContainsString('23:59:59', $context['optimized_clause']);
    }

    #[Test]
    public function it_handles_complex_queries_with_multiple_conditions(): void
    {
        // Arrange: Complex query with JOIN and multiple WHERE conditions
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT o.* FROM orders o ' .
                'LEFT JOIN customers c ON o.customer_id = c.id ' .
                'WHERE YEAR(o.created_at) = 2023 AND c.status = "active" ' .
                'ORDER BY o.created_at DESC',
                180.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertStringContainsString('YEAR(o.created_at)', $issue->getDescription());
    }

    #[Test]
    public function it_returns_correct_analyzer_metadata(): void
    {
        // Act
        $name = $this->analyzer->getName();
        $description = $this->analyzer->getDescription();

        // Assert
        self::assertEquals('Date Function Optimization Analyzer', $name);
        self::assertStringContainsString('date/time functions', $description);
        self::assertStringContainsString('index usage', $description);
    }
}
