<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\NullComparisonAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for NullComparisonAnalyzer.
 *
 * This analyzer detects incorrect NULL comparisons using = or != operators.
 */
final class NullComparisonAnalyzerTest extends TestCase
{
    private NullComparisonAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new NullComparisonAnalyzer(
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_no_null_comparisons(): void
    {
        // Arrange: Queries without NULL comparisons
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT id, name FROM users WHERE status = "active"')
            ->addQuery('SELECT * FROM products WHERE price > 100')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_returns_empty_collection_when_using_correct_is_null(): void
    {
        // Arrange: Correct IS NULL syntax
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE bonus IS NULL')
            ->addQuery('SELECT * FROM users WHERE bonus IS NOT NULL')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_equals_null_comparison(): void
    {
        // Arrange: Incorrect = NULL comparison
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE bonus = NULL')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertEquals('Integrity', $issue->getType());
        self::assertEquals('integrity', $issue->getCategory());
        self::assertEquals('critical', $issue->getSeverity()->value);
        self::assertStringContainsString('bonus = NULL', $issue->getDescription());
        self::assertStringContainsString('IS NULL', $issue->getDescription());
    }

    #[Test]
    public function it_detects_not_equals_null_comparison(): void
    {
        // Arrange: Incorrect != NULL comparison
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE bonus != NULL')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertStringContainsString('bonus != NULL', $issue->getDescription());
        self::assertStringContainsString('IS NOT NULL', $issue->getDescription());
    }

    #[Test]
    public function it_detects_not_equals_with_angle_brackets(): void
    {
        // Arrange: Incorrect <> NULL comparison
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE bonus <> NULL')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertStringContainsString('bonus <> NULL', $issue->getDescription());
    }

    #[Test]
    public function it_detects_case_insensitive_null(): void
    {
        // Arrange: NULL in different cases
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE bonus = null')
            ->addQuery('SELECT * FROM users WHERE status = Null')
            ->addQuery('SELECT * FROM users WHERE flag = nULl')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect all 3
        self::assertCount(3, $issues);
    }

    #[Test]
    public function it_detects_null_comparison_with_table_prefix(): void
    {
        // Arrange: Field with table prefix
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users u WHERE u.bonus = NULL')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertStringContainsString('u.bonus = NULL', $issue->getDescription());
        self::assertStringContainsString('u.bonus IS NULL', $issue->getDescription());
    }

    #[Test]
    public function it_detects_multiple_null_comparisons_in_same_query(): void
    {
        // Arrange: Multiple NULL comparisons
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE bonus = NULL AND status != NULL')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(2, $issues);
    }

    #[Test]
    public function it_provides_helpful_suggestion_for_equals(): void
    {
        // Arrange: = NULL
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE bonus = NULL')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
        self::assertStringContainsString('IS NULL', $issue->getDescription());
        self::assertStringContainsString('bonus', $issue->getDescription());
    }

    #[Test]
    public function it_provides_helpful_suggestion_for_not_equals(): void
    {
        // Arrange: != NULL
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE bonus != NULL')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
        self::assertStringContainsString('IS NOT NULL', $issue->getDescription());
    }

    #[Test]
    public function it_deduplicates_identical_comparisons_across_queries(): void
    {
        // Arrange: Same NULL comparison in multiple queries
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE bonus = NULL LIMIT 10')
            ->addQuery('SELECT * FROM users WHERE bonus = NULL LIMIT 20')
            ->addQuery('SELECT * FROM users WHERE bonus = NULL ORDER BY id')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should only report once
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_skips_empty_or_invalid_queries(): void
    {
        // Arrange: Invalid/minimal SQL without NULL comparisons
        // Note: QueryData now validates SQL is not empty
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT id FROM users')  // No NULL comparison = no issues
            ->addQuery('SELECT 1')              // No NULL comparison = no issues
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: No NULL comparisons = no issues
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_has_correct_analyzer_metadata(): void
    {
        // Assert
        self::assertEquals('NULL Comparison Analyzer', $this->analyzer->getName());
        self::assertStringContainsString('NULL comparisons', $this->analyzer->getDescription());
    }

    #[Test]
    public function it_includes_backtrace_when_available(): void
    {
        // Arrange: Query with backtrace
        $backtrace = [
            ['file' => 'UserRepository.php', 'line' => 123, 'function' => 'findUsersWithBonus'],
        ];
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace('SELECT * FROM users WHERE bonus = NULL', $backtrace)
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
    public function it_handles_complex_where_clauses(): void
    {
        // Arrange: Complex WHERE with NULL comparison
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE (status = "active" OR status = NULL) AND age > 18')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertStringContainsString('status = NULL', $issue->getDescription());
    }
}
