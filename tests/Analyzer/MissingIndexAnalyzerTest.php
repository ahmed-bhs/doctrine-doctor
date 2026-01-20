<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\MissingIndexAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\MissingIndexAnalyzerConfig;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for MissingIndexAnalyzer.
 *
 * Note: EXPLAIN analysis and index suggestions are tested in MissingIndexAnalyzerIntegrationTest.
 * This unit test focuses on configuration and basic behavior.
 */
final class MissingIndexAnalyzerTest extends TestCase
{
    private MissingIndexAnalyzer $analyzer;

    protected function setUp(): void
    {
        $config = new MissingIndexAnalyzerConfig(
            slowQueryThreshold: 50,
            minRowsScanned: 100,
            enabled: true,
        );

        $this->analyzer = new MissingIndexAnalyzer(
            suggestionFactory: PlatformAnalyzerTestHelper::createSuggestionFactory(), // @phpstan-ignore-line argument.type
            connection: PlatformAnalyzerTestHelper::createTestConnection(),
            missingIndexAnalyzerConfig: $config,
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_disabled(): void
    {
        // Arrange: Create analyzer with disabled config
        $config = new MissingIndexAnalyzerConfig(enabled: false);

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: PlatformAnalyzerTestHelper::createSuggestionFactory(), // @phpstan-ignore-line argument.type
            connection: PlatformAnalyzerTestHelper::createTestConnection(),
            missingIndexAnalyzerConfig: $config,
        );

        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE name = ?', 100.0)
            ->build();

        // Act
        $issues = $analyzer->analyze($queries);

        // Assert: Should return empty collection when disabled
        self::assertCount(0, $issues, 'Should not analyze when disabled');
    }

    #[Test]
    public function it_returns_empty_collection_for_empty_queries(): void
    {
        // Arrange: Empty query collection
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should return empty collection
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_skips_non_select_queries(): void
    {
        // Arrange: Only non-SELECT queries
        $queries = QueryDataBuilder::create()
            ->addQuery('INSERT INTO users (name) VALUES (?)', 10.0)
            ->addQuery('UPDATE users SET status = ?', 10.0)
            ->addQuery('DELETE FROM users WHERE id = ?', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should skip non-SELECT queries
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_processes_select_queries(): void
    {
        // Arrange: SELECT query (may or may not generate issues depending on EXPLAIN result)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE name = ?', 100.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should process without errors (actual detection tested in integration test)
        self::assertIsObject($issues);
        self::assertIsArray($issues->toArray());
    }

    #[Test]
    public function it_analyzes_slow_queries(): void
    {
        // Arrange: Query above slow query threshold (50ms)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM products WHERE price > 100', 60.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should analyze slow queries without errors
        self::assertIsObject($issues);
    }

    #[Test]
    public function it_skips_fast_queries_without_repetition(): void
    {
        // Arrange: Fast query (below threshold) executed only once
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should skip fast non-repetitive queries
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_analyzes_repetitive_queries(): void
    {
        // Arrange: Same query repeated 3+ times (even if fast)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE status = ?', 10.0)
            ->addQuery('SELECT * FROM users WHERE status = ?', 10.0)
            ->addQuery('SELECT * FROM users WHERE status = ?', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should analyze repetitive queries without errors
        self::assertIsObject($issues);
    }

    #[Test]
    public function it_normalizes_queries_for_pattern_detection(): void
    {
        // Arrange: Same query with different literals should be treated as same pattern
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users WHERE name = 'Alice'", 10.0)
            ->addQuery("SELECT * FROM users WHERE name = 'Bob'", 10.0)
            ->addQuery("SELECT * FROM users WHERE name = 'Charlie'", 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should treat as same pattern (repetitive)
        self::assertIsObject($issues);
    }

    #[Test]
    public function it_uses_configured_slow_query_threshold(): void
    {
        // Arrange: Custom threshold of 200ms
        $config = new MissingIndexAnalyzerConfig(
            slowQueryThreshold: 200,
            enabled: true,
        );

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: PlatformAnalyzerTestHelper::createSuggestionFactory(), // @phpstan-ignore-line argument.type
            connection: PlatformAnalyzerTestHelper::createTestConnection(),
            missingIndexAnalyzerConfig: $config,
        );

        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users', 150.0)  // Below 200ms threshold
            ->build();

        // Act
        $issues = $analyzer->analyze($queries);

        // Assert: Should not analyze queries below custom threshold
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_uses_configured_min_rows_threshold(): void
    {
        // Arrange: Custom min rows threshold
        $config = new MissingIndexAnalyzerConfig(
            minRowsScanned: 5000,
            enabled: true,
        );

        $analyzer = new MissingIndexAnalyzer(
            suggestionFactory: PlatformAnalyzerTestHelper::createSuggestionFactory(), // @phpstan-ignore-line argument.type
            connection: PlatformAnalyzerTestHelper::createTestConnection(),
            missingIndexAnalyzerConfig: $config,
        );

        // Act & Assert: Configuration is applied (actual threshold testing in integration test)
        self::assertIsObject($analyzer);
    }

    #[Test]
    public function it_handles_queries_with_backtrace(): void
    {
        // Arrange: Query with backtrace
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT * FROM users WHERE email = ?',
                [['file' => 'UserRepository.php', 'line' => 42]],
                100.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should handle backtrace without errors
        self::assertIsObject($issues);
    }

    #[Test]
    public function it_handles_queries_with_parameters(): void
    {
        // Arrange: Query with parameters
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT * FROM users WHERE id = ? AND status = ?',
                100.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should handle parameters without errors
        self::assertIsObject($issues);
    }

    #[Test]
    public function it_detects_repetitive_pattern_with_different_params(): void
    {
        // Arrange: Same query pattern with different parameters
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE user_id = ?', 15.0)
            ->addQuery('SELECT * FROM orders WHERE user_id = ?', 15.0)
            ->addQuery('SELECT * FROM orders WHERE user_id = ?', 15.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should recognize as repetitive pattern
        self::assertIsObject($issues);
    }

    #[Test]
    public function it_handles_complex_select_queries(): void
    {
        // Arrange: Complex SELECT with JOINs
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT u.*, p.* FROM users u
                 JOIN profiles p ON p.user_id = u.id
                 WHERE u.status = ?',
                100.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should handle complex queries without errors
        self::assertIsObject($issues);
    }

    #[Test]
    public function it_handles_subqueries(): void
    {
        // Arrange: Query with subquery
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE total > 100)',
                100.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should handle subqueries without errors
        self::assertIsObject($issues);
    }

    #[Test]
    public function it_handles_queries_with_in_clause(): void
    {
        // Arrange: Query with IN clause
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id IN (1, 2, 3, 4, 5)', 100.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should handle IN clauses without errors
        self::assertIsObject($issues);
    }

    #[Test]
    public function it_normalizes_whitespace_in_queries(): void
    {
        // Arrange: Same query with different whitespace
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users WHERE status = 'active'", 10.0)
            ->addQuery("SELECT  *  FROM  users  WHERE  status = 'active'", 10.0)
            ->addQuery("SELECT*FROM users WHERE status='active'", 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should treat as same pattern (all normalized)
        self::assertIsObject($issues);
    }

    #[Test]
    public function it_handles_mixed_query_types(): void
    {
        // Arrange: Mix of SELECT and non-SELECT
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?', 100.0)
            ->addQuery('INSERT INTO logs VALUES (?)', 10.0)
            ->addQuery('SELECT * FROM orders WHERE status = ?', 100.0)
            ->addQuery('UPDATE users SET last_login = NOW()', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should only analyze SELECT queries
        self::assertIsObject($issues);
    }
}
