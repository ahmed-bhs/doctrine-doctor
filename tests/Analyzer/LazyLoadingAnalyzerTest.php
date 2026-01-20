<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\LazyLoadingAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for LazyLoadingAnalyzer.
 *
 * This analyzer detects lazy loading patterns where entities are loaded
 * one by one in a loop (SELECT ... WHERE id = ? repeated multiple times).
 */
final class LazyLoadingAnalyzerTest extends TestCase
{
    private LazyLoadingAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new LazyLoadingAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            10, // threshold: 10 sequential queries to trigger detection
        );
    }

    #[Test]
    public function it_detects_lazy_loading_when_threshold_exceeded(): void
    {
        // Arrange: 15 sequential queries loading single entities (lazy loading pattern)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 15; $i++) {
            $queries->addQuery("SELECT t0.id, t0.name FROM users t0 WHERE t0.id = ?", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect lazy loading pattern
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should detect one lazy loading pattern');

        $issue = $issuesArray[0];
        self::assertStringContainsString('Lazy Loading', $issue->getTitle());
        self::assertStringContainsString('15 queries', $issue->getTitle());
    }

    #[Test]
    public function it_does_not_detect_lazy_loading_below_threshold(): void
    {
        // Arrange: Only 9 queries (below threshold of 10)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 9; $i++) {
            $queries->addQuery("SELECT t0.id, t0.name FROM users t0 WHERE t0.id = ?", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT detect lazy loading (below threshold)
        self::assertCount(0, $issues, 'Should not detect lazy loading below threshold');
    }

    #[Test]
    public function it_detects_lazy_loading_with_different_sql_patterns(): void
    {
        // Arrange: Various SELECT WHERE id = ? patterns
        $queries = QueryDataBuilder::create();

        // Pattern variations that should all be detected
        for ($i = 1; $i <= 12; $i++) {
            if (0 === $i % 3) {
                $queries->addQuery("SELECT * FROM posts WHERE posts.id = ?", 5.0);
            } elseif (1 === $i % 3) {
                $queries->addQuery("SELECT t0.* FROM posts t0 WHERE t0.id = ?", 5.0);
            } else {
                $queries->addQuery("SELECT p.id, p.title FROM posts p WHERE p.id = ?", 5.0);
            }
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect pattern despite slight SQL variations
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('12 queries', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_groups_lazy_loading_by_table(): void
    {
        // Arrange: Lazy loading on TWO different tables
        $queries = QueryDataBuilder::create();

        // 12 queries on users table
        for ($i = 1; $i <= 12; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = ?", 5.0);
        }

        // 11 queries on posts table
        for ($i = 1; $i <= 11; $i++) {
            $queries->addQuery("SELECT * FROM posts WHERE id = ?", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect BOTH patterns separately
        $issuesArray = $issues->toArray();
        self::assertCount(2, $issuesArray, 'Should detect lazy loading on both tables');

        $hasUsers = false;
        $hasPosts = false;

        foreach ($issuesArray as $issue) {
            $title = $issue->getTitle();
            if (str_contains($title, '12 queries')) {
                $hasUsers = true;
            }
            if (str_contains($title, '11 queries')) {
                $hasPosts = true;
            }
        }

        self::assertTrue($hasUsers, 'Should detect users lazy loading');
        self::assertTrue($hasPosts, 'Should detect posts lazy loading');
    }

    #[Test]
    public function it_only_detects_sequential_queries(): void
    {
        // Arrange: 15 queries on same table but NOT sequential (interleaved with other queries)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 15; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = ?", 5.0);
            // Interleave with many other queries (creates large gaps)
            $queries->addQuery("SELECT * FROM logs WHERE date > ?", 1.0);
            $queries->addQuery("SELECT * FROM settings WHERE key = ?", 1.0);
            $queries->addQuery("SELECT * FROM cache WHERE id = ?", 1.0);
            $queries->addQuery("SELECT COUNT(*) FROM stats", 1.0);
            $queries->addQuery("UPDATE counters SET value = ?", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT detect (queries are not close together, avg gap > 5)
        self::assertCount(0, $issues, 'Should not detect non-sequential queries');
    }

    #[Test]
    public function it_detects_sequential_queries_with_small_gaps(): void
    {
        // Arrange: 12 queries with small gaps (avg gap <= 5)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 12; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = ?", 5.0);
            // Small gap: only 1-2 queries between
            if (0 === $i % 3) {
                $queries->addQuery("SELECT * FROM other_table WHERE x = ?", 1.0);
            }
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect (small average gap)
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect sequential queries with small gaps');
    }

    #[Test]
    public function it_converts_table_name_to_entity_name(): void
    {
        // Arrange: Table name conversion (users -> Users, blog_posts -> BlogPosts)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 10; $i++) {
            $queries->addQuery("SELECT * FROM blog_posts WHERE id = ?", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Entity name should be converted to PascalCase
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $title = $issuesArray[0]->getTitle();
        self::assertStringContainsString('BlogPosts', $title, 'Should convert table to entity name');
    }

    #[Test]
    public function it_removes_table_prefix(): void
    {
        // Arrange: Table with prefix (tbl_users -> Users)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 10; $i++) {
            $queries->addQuery("SELECT * FROM tbl_users WHERE id = ?", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should remove tbl_ prefix
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $title = $issuesArray[0]->getTitle();
        self::assertStringContainsString('Users', $title);
        self::assertStringNotContainsString('tbl_', strtolower($title));
    }

    #[Test]
    public function it_infers_relation_from_backtrace(): void
    {
        // Arrange: Backtrace with getter method
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 10; $i++) {
            $queries->addQueryWithBacktrace(
                "SELECT * FROM comments WHERE id = ?",
                [
                    ['function' => 'getComments', 'class' => 'Post'],
                    ['function' => 'render', 'class' => 'Controller'],
                ],
                5.0,
            );
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should infer 'comments' relation from getComments()
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $description = $issuesArray[0]->getDescription();
        self::assertStringContainsString('comments', strtolower($description), 'Should infer relation from backtrace');
    }

    #[Test]
    public function it_provides_eager_loading_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 10; $i++) {
            $queries->addQuery("SELECT * FROM posts WHERE id = ?", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should provide eager loading suggestion
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $suggestion = $issuesArray[0]->getSuggestion();
        self::assertNotNull($suggestion, 'Should provide suggestion');
        self::assertStringContainsString('JOIN', strtoupper($suggestion->getCode()));
        self::assertStringContainsString('eager', strtolower($suggestion->getDescription()));
    }

    #[Test]
    public function it_includes_threshold_in_description(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 10; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = ?", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Description should mention threshold
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();

        self::assertStringContainsString('threshold', strtolower($description));
        self::assertStringContainsString('10', $description);
    }

    #[Test]
    public function it_mentions_join_fetch_in_description(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 10; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = ?", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should mention JOIN FETCH as solution
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();

        self::assertStringContainsString('JOIN FETCH', $description);
    }

    #[Test]
    public function it_includes_backtrace(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 10; $i++) {
            $queries->addQueryWithBacktrace(
                "SELECT * FROM users WHERE id = ?",
                [['file' => 'UserController.php', 'line' => 42]],
                5.0,
            );
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should include backtrace
        $issuesArray = $issues->toArray();
        $backtrace = $issuesArray[0]->getBacktrace();

        self::assertNotNull($backtrace);
        self::assertIsArray($backtrace);
        self::assertArrayHasKey('file', $backtrace[0]);
        self::assertEquals('UserController.php', $backtrace[0]['file']);
    }

    #[Test]
    public function it_limits_queries_in_issue_to_20(): void
    {
        // Arrange: 50 lazy loading queries
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 50; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = ?", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should limit to first 20 queries for performance
        $issuesArray = $issues->toArray();
        $issue = $issuesArray[0];

        // The issue should report 50 queries but only include 20 in details
        self::assertStringContainsString('50 queries', $issue->getTitle());
        self::assertLessThanOrEqual(20, count($issue->getQueries()));
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
    public function it_ignores_non_lazy_loading_queries(): void
    {
        // Arrange: Various queries that are NOT lazy loading patterns
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users', 5.0)
            ->addQuery('SELECT * FROM posts WHERE title = ?', 5.0)
            ->addQuery('INSERT INTO logs VALUES (?)', 5.0)
            ->addQuery('UPDATE settings SET value = ?', 5.0)
            ->addQuery('DELETE FROM cache WHERE expired = 1', 5.0)
            ->addQuery('SELECT COUNT(*) FROM stats', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect (no WHERE id = ? pattern)
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_requires_where_id_equals_placeholder(): void
    {
        // Arrange: SELECT queries but without WHERE id = ? pattern
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 15; $i++) {
            // Different WHERE conditions (not id = ?)
            $queries->addQuery("SELECT * FROM users WHERE name = ?", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT detect (requires WHERE id = ? specifically)
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_has_performance_category(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 10; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = ?", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert
        $issuesArray = $issues->toArray();
        self::assertEquals('performance', $issuesArray[0]->getCategory());
    }

    #[Test]
    public function it_detects_pattern_with_table_alias_in_where(): void
    {
        // Arrange: Pattern with table alias (t0.id, u.id, etc.)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 10; $i++) {
            $queries->addQuery("SELECT u.* FROM users u WHERE u.id = ?", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect despite table alias
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
    }

    #[Test]
    public function it_does_not_match_foreign_key_columns(): void
    {
        // Arrange: Foreign key columns (user_id, product_id, etc.) should NOT be detected
        $queries = QueryDataBuilder::create();

        // These are NOT lazy loading - they're queries filtering by foreign keys
        for ($i = 1; $i <= 15; $i++) {
            $queries->addQuery("SELECT * FROM orders WHERE user_id = ?", 5.0);
            $queries->addQuery("SELECT * FROM posts WHERE author_id = ?", 5.0);
            $queries->addQuery("SELECT t0.* FROM product_variant t0 WHERE t0.product_id = ?", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT detect (foreign keys are not lazy loading)
        self::assertCount(0, $issues, 'Should not match foreign key columns like user_id, product_id');
    }
}
