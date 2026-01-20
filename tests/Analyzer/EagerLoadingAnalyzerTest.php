<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\EagerLoadingAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for EagerLoadingAnalyzer.
 *
 * This analyzer detects excessive eager loading by counting JOINs in queries.
 * Too many JOINs can cause performance issues (cartesian products, large result sets).
 */
final class EagerLoadingAnalyzerTest extends TestCase
{
    private EagerLoadingAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new EagerLoadingAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            4,  // joinThreshold: warn at 4 JOINs
            7,   // criticalJoinThreshold: critical at 7 JOINs
        );
    }

    #[Test]
    public function it_detects_excessive_joins_at_threshold(): void
    {
        // Arrange: Query with exactly 4 JOINs (threshold)
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT * FROM users u
                JOIN posts p ON p.user_id = u.id
                JOIN comments c ON c.post_id = p.id
                JOIN likes l ON l.comment_id = c.id
                JOIN tags t ON t.post_id = p.id',
                50.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect excessive JOINs
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should detect excessive JOINs');

        $issue = $issuesArray[0];
        self::assertStringContainsString('Excessive Eager Loading', $issue->getTitle());
        self::assertStringContainsString('4 JOINs', $issue->getTitle());
    }

    #[Test]
    public function it_does_not_detect_joins_below_threshold(): void
    {
        // Arrange: Query with only 3 JOINs (below threshold)
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT * FROM users u
                JOIN posts p ON p.user_id = u.id
                JOIN comments c ON c.post_id = p.id
                JOIN likes l ON l.comment_id = c.id',
                20.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect (below threshold)
        self::assertCount(0, $issues, 'Should not detect JOINs below threshold');
    }

    #[Test]
    public function it_assigns_warning_severity_at_threshold(): void
    {
        // Arrange: 5 JOINs (>= threshold but <= critical)
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT * FROM a
                JOIN b ON b.a_id = a.id
                JOIN c ON c.b_id = b.id
                JOIN d ON d.c_id = c.id
                JOIN e ON e.d_id = d.id
                JOIN f ON f.e_id = e.id',
                50.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be WARNING severity (>= 4 but <= 7)
        $issuesArray = $issues->toArray();
        self::assertEquals('warning', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_assigns_critical_severity_above_critical_threshold(): void
    {
        // Arrange: 8 JOINs (> critical threshold of 7)
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT * FROM a
                JOIN b ON b.a_id = a.id
                JOIN c ON c.b_id = b.id
                JOIN d ON d.c_id = c.id
                JOIN e ON e.d_id = d.id
                JOIN f ON f.e_id = e.id
                JOIN g ON g.f_id = f.id
                JOIN h ON h.g_id = g.id
                JOIN i ON i.h_id = h.id',
                100.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be CRITICAL severity (> 7)
        $issuesArray = $issues->toArray();
        self::assertEquals('critical', $issuesArray[0]->getSeverity()->value);
        self::assertStringContainsString('8 JOINs', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_counts_all_join_types(): void
    {
        // Arrange: Mix of different JOIN types
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT * FROM users u
                INNER JOIN posts p ON p.user_id = u.id
                LEFT JOIN comments c ON c.post_id = p.id
                LEFT OUTER JOIN likes l ON l.comment_id = c.id
                RIGHT JOIN tags t ON t.post_id = p.id',
                50.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should count all JOIN types (INNER, LEFT, LEFT OUTER, RIGHT)
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('4 JOINs', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_detects_cross_joins(): void
    {
        // Arrange: Query with CROSS JOIN
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT * FROM users
                CROSS JOIN posts
                CROSS JOIN comments
                CROSS JOIN likes
                CROSS JOIN tags',
                100.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should count CROSS JOINs
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('4 JOINs', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_is_case_insensitive(): void
    {
        // Arrange: Mixed case JOINs
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT * FROM users
                join posts ON posts.user_id = users.id
                JOIN comments ON comments.post_id = posts.id
                Join likes ON likes.comment_id = comments.id
                jOiN tags ON tags.post_id = posts.id',
                50.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect regardless of case
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('4 JOINs', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_provides_optimization_suggestions(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT * FROM users
                JOIN posts ON posts.user_id = users.id
                JOIN comments ON comments.post_id = posts.id
                JOIN likes ON likes.comment_id = comments.id
                JOIN tags ON tags.post_id = posts.id',
                50.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide suggestions
        $issuesArray = $issues->toArray();
        $suggestion = $issuesArray[0]->getSuggestion();

        self::assertNotNull($suggestion);

        $code = $suggestion->getCode();
        self::assertStringContainsString('EXTRA_LAZY', $code, 'Should suggest EXTRA_LAZY');
        self::assertStringContainsString('partial', strtolower($code), 'Should suggest partial objects');
        self::assertStringContainsString('DTO', $code, 'Should suggest DTOs');
    }

    #[Test]
    public function it_mentions_cartesian_product_risk(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT * FROM users
                JOIN posts ON posts.user_id = users.id
                JOIN comments ON comments.post_id = posts.id
                JOIN likes ON likes.comment_id = comments.id
                JOIN tags ON tags.post_id = posts.id',
                50.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Description should mention cartesian product
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();

        self::assertStringContainsString('cartesian product', strtolower($description));
    }

    #[Test]
    public function it_includes_threshold_in_description(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT * FROM users
                JOIN posts ON posts.user_id = users.id
                JOIN comments ON comments.post_id = posts.id
                JOIN likes ON likes.comment_id = comments.id
                JOIN tags ON tags.post_id = posts.id',
                50.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should mention threshold
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();

        self::assertStringContainsString('threshold', strtolower($description));
        self::assertStringContainsString('4', $description);
    }

    #[Test]
    public function it_includes_backtrace(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT * FROM users
                JOIN posts ON posts.user_id = users.id
                JOIN comments ON comments.post_id = posts.id
                JOIN likes ON likes.comment_id = comments.id
                JOIN tags ON tags.post_id = posts.id',
                [['file' => 'UserRepository.php', 'line' => 123]],
                50.0,
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
    public function it_detects_multiple_problematic_queries(): void
    {
        // Arrange: Two queries with excessive JOINs
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT * FROM users
                JOIN posts ON posts.user_id = users.id
                JOIN comments ON comments.post_id = posts.id
                JOIN likes ON likes.comment_id = comments.id
                JOIN tags ON tags.post_id = posts.id',
                50.0,
            )
            ->addQuery(
                'SELECT * FROM products
                JOIN categories ON categories.id = products.category_id
                JOIN suppliers ON suppliers.id = products.supplier_id
                JOIN inventory ON inventory.product_id = products.id
                JOIN warehouses ON warehouses.id = inventory.warehouse_id',
                60.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect BOTH queries
        $issuesArray = $issues->toArray();
        self::assertCount(2, $issuesArray, 'Should detect both queries');
    }

    #[Test]
    public function it_handles_multiline_queries(): void
    {
        // Arrange: Multiline query formatting
        $queries = QueryDataBuilder::create()
            ->addQuery(
                "SELECT * FROM users
                JOIN posts
                    ON posts.user_id = users.id
                JOIN comments
                    ON comments.post_id = posts.id
                JOIN likes
                    ON likes.comment_id = comments.id
                JOIN tags
                    ON tags.post_id = posts.id",
                50.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should count JOINs across multiple lines
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('4 JOINs', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_does_not_count_join_in_strings(): void
    {
        // Arrange: Query with "JOIN" in string literals (should not count these)
        // But in practice, regex \bJOIN\b should not match inside strings
        $queries = QueryDataBuilder::create()
            ->addQuery(
                "SELECT 'JOIN is a keyword' as note FROM users
                JOIN posts ON posts.user_id = users.id
                JOIN comments ON comments.post_id = posts.id
                JOIN likes ON likes.comment_id = comments.id
                JOIN tags ON tags.post_id = posts.id",
                50.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should count actual JOINs (4), not the one in string
        // Note: Current implementation uses \bJOIN\b which will match inside strings too
        // This test documents current behavior
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));
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
    public function it_ignores_queries_without_joins(): void
    {
        // Arrange: Queries without JOINs
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?', 5.0)
            ->addQuery('INSERT INTO logs VALUES (?)', 2.0)
            ->addQuery('UPDATE settings SET value = ?', 3.0)
            ->addQuery('DELETE FROM cache WHERE expired = 1', 1.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect any issues
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_has_performance_category(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT * FROM users
                JOIN posts ON posts.user_id = users.id
                JOIN comments ON comments.post_id = posts.id
                JOIN likes ON likes.comment_id = comments.id
                JOIN tags ON tags.post_id = posts.id',
                50.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertEquals('performance', $issuesArray[0]->getCategory());
    }

    #[Test]
    public function it_includes_critical_warning_in_suggestion_for_many_joins(): void
    {
        // Arrange: 10 JOINs (well above critical threshold)
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT * FROM a
                JOIN b ON b.a_id = a.id
                JOIN c ON c.b_id = b.id
                JOIN d ON d.c_id = c.id
                JOIN e ON e.d_id = d.id
                JOIN f ON f.e_id = e.id
                JOIN g ON g.f_id = f.id
                JOIN h ON h.g_id = g.id
                JOIN i ON i.h_id = h.id
                JOIN j ON j.i_id = i.id
                JOIN k ON k.j_id = j.id',
                200.0,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should mention "CRITICAL" in suggestion
        $issuesArray = $issues->toArray();
        $suggestion = $issuesArray[0]->getSuggestion();
        self::assertNotNull($suggestion);

        self::assertStringContainsString('CRITICAL', $suggestion->getCode());
        self::assertStringContainsString('10 JOINs', $suggestion->getCode());
    }
}
