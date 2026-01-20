<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\SlowQueryAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for SlowQueryAnalyzer.
 *
 * This analyzer detects queries that exceed a configurable execution time threshold.
 */
final class SlowQueryAnalyzerTest extends TestCase
{
    private SlowQueryAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new SlowQueryAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            100,  // threshold: 100ms
        );
    }

    #[Test]
    public function it_detects_slow_query_above_threshold(): void
    {
        // Arrange: Query with 150ms execution time (above 100ms threshold)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE name = ?', 150.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect slow query
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should detect slow query');

        $issue = $issuesArray[0];
        self::assertStringContainsString('Slow Query', $issue->getTitle());
        self::assertStringContainsString('150', $issue->getTitle());
    }

    #[Test]
    public function it_does_not_detect_fast_queries(): void
    {
        // Arrange: Query with 50ms execution time (below 100ms threshold)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?', 50.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect fast query
        self::assertCount(0, $issues, 'Should not detect fast queries');
    }

    #[Test]
    public function it_detects_query_at_exact_threshold(): void
    {
        // Arrange: Query with exactly 100ms (at threshold)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users', 100.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect (threshold is exclusive: > threshold)
        // Based on QueryDataCollection::filterSlow() behavior
        self::assertCount(0, $issues, 'Should not detect at exact threshold');
    }

    #[Test]
    public function it_detects_multiple_slow_queries(): void
    {
        // Arrange: Multiple slow queries
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE email = ?', 150.0)
            ->addQuery('SELECT * FROM orders WHERE total > 100', 200.0)
            ->addQuery('SELECT * FROM products WHERE price < 50', 120.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect all 3 slow queries
        $issuesArray = $issues->toArray();
        self::assertCount(3, $issuesArray, 'Should detect all slow queries');
    }

    #[Test]
    public function it_filters_fast_from_slow_queries(): void
    {
        // Arrange: Mix of fast and slow queries
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 10.0)  // Fast
            ->addQuery('SELECT * FROM orders', 200.0)  // Slow
            ->addQuery('SELECT * FROM products WHERE id = 2', 20.0)  // Fast
            ->addQuery('SELECT * FROM categories', 150.0)  // Slow
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should only detect the 2 slow queries
        $issuesArray = $issues->toArray();
        self::assertCount(2, $issuesArray, 'Should only detect slow queries');
    }

    #[Test]
    public function it_uses_custom_threshold(): void
    {
        // Arrange: Analyzer with 200ms threshold
        $analyzer = new SlowQueryAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            200,
        );

        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users', 150.0)  // Below 200ms
            ->addQuery('SELECT * FROM orders', 250.0)  // Above 200ms
            ->build();

        // Act
        $issues = $analyzer->analyze($queries);

        // Assert: Should only detect query above 200ms
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should detect based on custom threshold');
    }

    #[Test]
    public function it_includes_execution_time_in_title(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM products', 123.45)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Title should include execution time
        $issuesArray = $issues->toArray();
        $title = $issuesArray[0]->getTitle();

        self::assertStringContainsString('123.45', $title, 'Should include execution time');
        self::assertStringContainsString('ms', strtolower($title), 'Should include milliseconds unit');
    }

    #[Test]
    public function it_includes_threshold_in_description(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users', 150.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Description should mention threshold
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();

        self::assertStringContainsString('100', $description, 'Should mention threshold');
        self::assertStringContainsString('threshold', strtolower($description));
    }

    #[Test]
    public function it_provides_optimization_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE name = ?', 150.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide suggestion
        $issuesArray = $issues->toArray();
        $suggestion = $issuesArray[0]->getSuggestion();

        self::assertNotNull($suggestion, 'Should provide suggestion');
        self::assertNotEmpty($suggestion->getCode(), 'Should have code in suggestion');
    }

    #[Test]
    public function it_gets_severity_from_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users', 150.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Severity should come from suggestion metadata
        $issuesArray = $issues->toArray();
        $issue = $issuesArray[0];

        self::assertNotNull($issue->getSeverity());
        $suggestion = $issue->getSuggestion();
        self::assertNotNull($suggestion);
        self::assertEquals(
            $suggestion->getMetadata()->severity,
            $issue->getSeverity(),
            'Severity should match suggestion metadata',
        );
    }

    #[Test]
    public function it_detects_subquery_optimization_hint(): void
    {
        // Arrange: Query with subquery
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT * FROM users WHERE id IN (SELECT user_id FROM orders)',
                150.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should suggest JOIN optimization
        $issuesArray = $issues->toArray();
        $suggestion = $issuesArray[0]->getSuggestion();
        self::assertNotNull($suggestion);

        self::assertStringContainsString('JOIN', strtoupper($suggestion->getCode()));
    }

    #[Test]
    public function it_detects_order_by_optimization_hint(): void
    {
        // Arrange: Query with ORDER BY
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users ORDER BY created_at DESC', 150.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should suggest indexing ORDER BY columns
        $issuesArray = $issues->toArray();
        $suggestion = $issuesArray[0]->getSuggestion();
        self::assertNotNull($suggestion);

        self::assertStringContainsString('ORDER BY', strtoupper($suggestion->getCode()));
        self::assertStringContainsString('index', strtolower($suggestion->getCode()));
    }

    #[Test]
    public function it_detects_group_by_optimization_hint(): void
    {
        // Arrange: Query with GROUP BY
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT category_id, COUNT(*) FROM products GROUP BY category_id', 150.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should suggest indexing GROUP BY columns
        $issuesArray = $issues->toArray();
        $suggestion = $issuesArray[0]->getSuggestion();
        self::assertNotNull($suggestion);

        self::assertStringContainsString('GROUP BY', strtoupper($suggestion->getCode()));
        self::assertStringContainsString('index', strtolower($suggestion->getCode()));
    }

    #[Test]
    public function it_detects_leading_wildcard_like_hint(): void
    {
        // Arrange: Query with leading wildcard LIKE
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users WHERE email LIKE '%@example.com'", 150.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should warn about leading wildcard
        $issuesArray = $issues->toArray();
        $suggestion = $issuesArray[0]->getSuggestion();
        self::assertNotNull($suggestion);

        self::assertStringContainsString('wildcard', strtolower($suggestion->getCode()));
    }

    #[Test]
    public function it_detects_distinct_optimization_hint(): void
    {
        // Arrange: Query with SELECT DISTINCT
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT DISTINCT category_id FROM products', 150.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should mention DISTINCT expense
        $issuesArray = $issues->toArray();
        $suggestion = $issuesArray[0]->getSuggestion();
        self::assertNotNull($suggestion);

        self::assertStringContainsString('DISTINCT', strtoupper($suggestion->getCode()));
    }

    #[Test]
    public function it_provides_generic_suggestion_for_simple_queries(): void
    {
        // Arrange: Simple query without specific patterns
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?', 150.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide generic optimization advice
        $issuesArray = $issues->toArray();
        $suggestion = $issuesArray[0]->getSuggestion();
        self::assertNotNull($suggestion);

        self::assertStringContainsString('index', strtolower($suggestion->getCode()));
    }

    #[Test]
    public function it_includes_backtrace(): void
    {
        // Arrange: Query with backtrace
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT * FROM users',
                [['file' => 'UserRepository.php', 'line' => 42]],
                150.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should include backtrace
        $issuesArray = $issues->toArray();
        $backtrace = $issuesArray[0]->getBacktrace();

        self::assertNotNull($backtrace);
        self::assertIsArray($backtrace);
        self::assertEquals('UserRepository.php', $backtrace[0]['file']);
    }

    #[Test]
    public function it_includes_query_in_issue(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM slow_table', 200.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should include the query
        $issuesArray = $issues->toArray();
        $issueQueries = $issuesArray[0]->getQueries();

        self::assertNotEmpty($issueQueries);
        self::assertCount(1, $issueQueries);
    }

    #[Test]
    public function it_handles_empty_query_collection(): void
    {
        // Arrange: Empty collection
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should return empty collection
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_has_performance_category(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users', 150.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertEquals('performance', $issuesArray[0]->getCategory());
    }

    #[Test]
    public function it_rejects_invalid_threshold_zero(): void
    {
        // Assert: Should reject zero threshold
        $this->expectException(\InvalidArgumentException::class);

        new SlowQueryAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            0,
        );
    }

    #[Test]
    public function it_rejects_invalid_threshold_negative(): void
    {
        // Assert: Should reject negative threshold
        $this->expectException(\InvalidArgumentException::class);

        new SlowQueryAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            -100,
        );
    }

    #[Test]
    public function it_rejects_unreasonably_high_threshold(): void
    {
        // Assert: Should reject threshold > 100s (100000ms)
        $this->expectException(\InvalidArgumentException::class);

        new SlowQueryAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            150000,
        );
    }

    #[Test]
    public function it_uses_suggestion_factory(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users', 150.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should use suggestion factory (has metadata)
        $issuesArray = $issues->toArray();
        $suggestion = $issuesArray[0]->getSuggestion();
        self::assertNotNull($suggestion);

        self::assertNotNull($suggestion->getMetadata(), 'Should have metadata from factory');
    }
}
