<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\DivisionByZeroAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for DivisionByZeroAnalyzer.
 *
 * This analyzer detects division operations that could result in division by zero errors.
 */
final class DivisionByZeroAnalyzerTest extends TestCase
{
    private DivisionByZeroAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new DivisionByZeroAnalyzer(
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_no_divisions_in_query(): void
    {
        // Arrange: Query without any division operations
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT id, name, price FROM products')
            ->addQuery('SELECT * FROM users WHERE status = "active"')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_returns_empty_collection_when_division_is_protected_with_nullif(): void
    {
        // Arrange: Query with NULLIF protection
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT revenue / NULLIF(quantity, 0) FROM sales')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_returns_empty_collection_when_division_is_protected_with_coalesce(): void
    {
        // Arrange: Query with COALESCE protection
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT revenue / COALESCE(quantity, 1) FROM sales')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_returns_empty_collection_when_division_is_protected_with_case(): void
    {
        // Arrange: Query with CASE WHEN protection
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT CASE WHEN quantity > 0 THEN revenue / quantity ELSE 0 END FROM sales')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_returns_empty_collection_when_divisor_is_non_zero_constant(): void
    {
        // Arrange: Division by non-zero constant is safe
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT price / 100 FROM products')
            ->addQuery('SELECT total / 12.5 FROM monthly_data')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_unprotected_division_in_select(): void
    {
        // Arrange: Unprotected division that could fail
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT revenue / quantity FROM sales')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertEquals('Security', $issue->getType());
        self::assertEquals('security', $issue->getCategory());
        self::assertEquals('critical', $issue->getSeverity()->value);
        self::assertStringContainsString('revenue / quantity', $issue->getDescription());
    }

    #[Test]
    public function it_detects_multiple_divisions_in_same_query(): void
    {
        // Arrange: Query with multiple unprotected divisions
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT revenue / quantity, profit / cost FROM sales')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(2, $issues);
        $types = array_map(fn ($issue) => $issue->getType(), $issues->toArray());
        self::assertEquals(['Security', 'Security'], $types);
    }

    #[Test]
    public function it_detects_division_with_table_prefix(): void
    {
        // Arrange: Division with table.field notation
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT s.revenue / s.quantity FROM sales s')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertStringContainsString('s.revenue / s.quantity', $issue->getDescription());
    }

    #[Test]
    public function it_provides_helpful_suggestion_with_nullif(): void
    {
        // Arrange: Unprotected division
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT revenue / quantity FROM sales')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
        self::assertStringContainsString('NULLIF', $issue->getDescription());
        self::assertStringContainsString('quantity', $issue->getDescription());
    }

    #[Test]
    public function it_deduplicates_identical_divisions_across_queries(): void
    {
        // Arrange: Same division in multiple queries
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT revenue / quantity FROM sales WHERE id = 1')
            ->addQuery('SELECT revenue / quantity FROM sales WHERE id = 2')
            ->addQuery('SELECT revenue / quantity FROM sales WHERE id = 3')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should only report once
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_skips_empty_or_invalid_queries(): void
    {
        // Arrange: Invalid/minimal SQL without divisions
        // Note: QueryData now validates SQL is not empty
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT id FROM users')  // No division = no issues
            ->addQuery('SELECT 1')              // No division = no issues
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: No divisions = no issues
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_has_correct_analyzer_metadata(): void
    {
        // Assert
        self::assertEquals('Division By Zero Analyzer', $this->analyzer->getName());
        self::assertStringContainsString('division by zero', $this->analyzer->getDescription());
    }

    #[Test]
    public function it_includes_backtrace_when_available(): void
    {
        // Arrange: Query with backtrace
        $backtrace = [
            ['file' => 'SalesRepository.php', 'line' => 42, 'function' => 'calculateAverage'],
        ];
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace('SELECT revenue / quantity FROM sales', $backtrace)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertNotNull($issue->getBacktrace());
        self::assertEquals($backtrace, $issue->getBacktrace());
    }
}
