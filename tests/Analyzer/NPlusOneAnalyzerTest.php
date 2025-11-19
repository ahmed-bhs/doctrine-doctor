<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\NPlusOneAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for NPlusOneAnalyzer.
 *
 * This analyzer detects N+1 query problems by identifying when the same
 * query pattern is executed multiple times (above a threshold).
 */
final class NPlusOneAnalyzerTest extends TestCase
{
    private NPlusOneAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity',
        ]);

        $this->analyzer = new NPlusOneAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            5, // threshold: 5 similar queries to trigger detection
        );
    }

    #[Test]
    public function it_detects_n_plus_one_when_threshold_exceeded(): void
    {
        // Arrange: 10 identical queries (above threshold of 5)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 10.5)
            ->addQuery('SELECT * FROM users WHERE id = 2', 11.2)
            ->addQuery('SELECT * FROM users WHERE id = 3', 9.8)
            ->addQuery('SELECT * FROM users WHERE id = 4', 10.1)
            ->addQuery('SELECT * FROM users WHERE id = 5', 10.9)
            ->addQuery('SELECT * FROM users WHERE id = 6', 11.5)
            ->addQuery('SELECT * FROM users WHERE id = 7', 10.3)
            ->addQuery('SELECT * FROM users WHERE id = 8', 9.9)
            ->addQuery('SELECT * FROM users WHERE id = 9', 10.7)
            ->addQuery('SELECT * FROM users WHERE id = 10', 11.1)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect the N+1 pattern
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should detect one N+1 pattern');

        $issue = $issuesArray[0];
        self::assertStringContainsString('N+1', $issue->getTitle());
        self::assertStringContainsString('10 queries', $issue->getTitle());
    }

    #[Test]
    public function it_does_not_detect_n_plus_one_below_threshold(): void
    {
        // Arrange: Only 4 similar queries (below threshold of 5)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 10.5)
            ->addQuery('SELECT * FROM users WHERE id = 2', 11.2)
            ->addQuery('SELECT * FROM users WHERE id = 3', 9.8)
            ->addQuery('SELECT * FROM users WHERE id = 4', 10.1)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect N+1 (below threshold)
        self::assertCount(0, $issues, 'Should not detect N+1 below threshold');
    }

    #[Test]
    public function it_normalizes_query_parameters(): void
    {
        // Arrange: Queries with different parameters but same pattern
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM posts WHERE user_id = 1", 5.0)
            ->addQuery("SELECT * FROM posts WHERE user_id = 2", 5.1)
            ->addQuery("SELECT * FROM posts WHERE user_id = 3", 5.2)
            ->addQuery("SELECT * FROM posts WHERE user_id = 4", 5.3)
            ->addQuery("SELECT * FROM posts WHERE user_id = 5", 5.4)
            ->addQuery("SELECT * FROM posts WHERE user_id = 6", 5.5)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should recognize as same pattern despite different IDs
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should detect pattern despite different parameters');

        $issue = $issuesArray[0];
        self::assertStringContainsString('6 queries', $issue->getTitle());
    }

    #[Test]
    public function it_normalizes_string_literals(): void
    {
        // Arrange: Queries with different string values
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users WHERE name = 'Alice'", 5.0)
            ->addQuery("SELECT * FROM users WHERE name = 'Bob'", 5.1)
            ->addQuery("SELECT * FROM users WHERE name = 'Charlie'", 5.2)
            ->addQuery("SELECT * FROM users WHERE name = 'David'", 5.3)
            ->addQuery("SELECT * FROM users WHERE name = 'Eve'", 5.4)
            ->addQuery("SELECT * FROM users WHERE name = 'Frank'", 5.5)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should recognize as same pattern
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('6 queries', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_normalizes_in_clauses(): void
    {
        // Arrange: Queries with different IN clause contents
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM posts WHERE id IN (1, 2, 3)", 5.0)
            ->addQuery("SELECT * FROM posts WHERE id IN (4, 5)", 5.1)
            ->addQuery("SELECT * FROM posts WHERE id IN (6, 7, 8, 9)", 5.2)
            ->addQuery("SELECT * FROM posts WHERE id IN (10)", 5.3)
            ->addQuery("SELECT * FROM posts WHERE id IN (11, 12)", 5.4)
            ->addQuery("SELECT * FROM posts WHERE id IN (13, 14, 15)", 5.5)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should recognize as same pattern
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('6 queries', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_calculates_total_execution_time(): void
    {
        // Arrange: 6 queries with 10ms each = 60ms total
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 4', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Description should mention total time
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $description = $issuesArray[0]->getDescription();
        self::assertStringContainsString('60.00', $description, 'Should show total execution time');
    }

    #[Test]
    public function it_assigns_info_severity_for_small_impact(): void
    {
        // Arrange: 6 queries (above threshold) but low execution time
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 1.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 1.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 1.0)
            ->addQuery('SELECT * FROM users WHERE id = 4', 1.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 1.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 1.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be INFO severity (< 10 queries, < 500ms total)
        $issuesArray = $issues->toArray();
        self::assertEquals('info', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_assigns_warning_severity_for_medium_impact(): void
    {
        // Arrange: 12 collection queries = LOW with new 5-level system (10-14 queries)
        // Use foreign_key pattern to avoid proxy multiplier
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 12; $i++) {
            $queries->addQuery("SELECT * FROM posts WHERE user_id = {$i}", 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should be LOW severity (10-14 queries for collection N+1)
        $issuesArray = $issues->toArray();
        self::assertEquals('warning', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_assigns_critical_severity_for_many_queries(): void
    {
        // Arrange: 25 queries (above 20) = critical
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 25; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should be CRITICAL severity (> 20 queries)
        $issuesArray = $issues->toArray();
        self::assertEquals('critical', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_assigns_critical_severity_for_high_total_time(): void
    {
        // Arrange: 6 queries with 200ms each = 1200ms total (> 1000ms)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 200.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 200.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 200.0)
            ->addQuery('SELECT * FROM users WHERE id = 4', 200.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 200.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 200.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be CRITICAL severity (> 1000ms total)
        $issuesArray = $issues->toArray();
        self::assertEquals('critical', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_assigns_warning_severity_for_medium_total_time(): void
    {
        // Arrange: 6 queries with 100ms each = 600ms total (> 500ms but < 700ms)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 100.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 100.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 100.0)
            ->addQuery('SELECT * FROM users WHERE id = 4', 100.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 100.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 100.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be MEDIUM severity (> 500ms total, with 5-level system)
        $issuesArray = $issues->toArray();
        self::assertEquals('warning', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_generates_join_fetch_suggestion_for_foreign_key_pattern(): void
    {
        // Arrange: Pattern matching "WHERE t0.xxx_id = ?"
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM comments t0 WHERE t0.post_id = 1', 5.0)
            ->addQuery('SELECT * FROM comments t0 WHERE t0.post_id = 2', 5.0)
            ->addQuery('SELECT * FROM comments t0 WHERE t0.post_id = 3', 5.0)
            ->addQuery('SELECT * FROM comments t0 WHERE t0.post_id = 4', 5.0)
            ->addQuery('SELECT * FROM comments t0 WHERE t0.post_id = 5', 5.0)
            ->addQuery('SELECT * FROM comments t0 WHERE t0.post_id = 6', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide JOIN FETCH suggestion
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $suggestion = $issuesArray[0]->getSuggestion();
        self::assertNotNull($suggestion, 'Should provide suggestion');
        self::assertStringContainsString('JOIN', $suggestion->getCode());
        self::assertStringContainsString('post', strtolower($suggestion->getCode()));
    }

    #[Test]
    public function it_generates_suggestion_for_simple_select_pattern(): void
    {
        // Arrange: Simple SELECT with foreign key
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT id, title FROM posts WHERE author_id = 1', 5.0)
            ->addQuery('SELECT id, title FROM posts WHERE author_id = 2', 5.0)
            ->addQuery('SELECT id, title FROM posts WHERE author_id = 3', 5.0)
            ->addQuery('SELECT id, title FROM posts WHERE author_id = 4', 5.0)
            ->addQuery('SELECT id, title FROM posts WHERE author_id = 5', 5.0)
            ->addQuery('SELECT id, title FROM posts WHERE author_id = 6', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide suggestion
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $suggestion = $issuesArray[0]->getSuggestion();
        self::assertNotNull($suggestion, 'Should provide suggestion for simple SELECT');
    }

    #[Test]
    public function it_detects_multiple_n_plus_one_patterns(): void
    {
        // Arrange: Two different N+1 patterns
        $queries = QueryDataBuilder::create()
            // Pattern 1: Loading posts by user_id (6 times)
            ->addQuery('SELECT * FROM posts WHERE user_id = 1', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 2', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 3', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 4', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 5', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 6', 5.0)
            // Pattern 2: Loading comments by post_id (7 times)
            ->addQuery('SELECT * FROM comments WHERE post_id = 1', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 2', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 3', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 4', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 5', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 6', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 7', 3.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect BOTH patterns
        $issuesArray = $issues->toArray();
        self::assertCount(2, $issuesArray, 'Should detect two different N+1 patterns');

        // Verify both patterns are detected
        $hasPosts = false;
        $hasComments = false;

        foreach ($issuesArray as $issue) {
            if (str_contains($issue->getTitle(), '6 queries')) {
                $hasPosts = true;
            }
            if (str_contains($issue->getTitle(), '7 queries')) {
                $hasComments = true;
            }
        }

        self::assertTrue($hasPosts, 'Should detect posts pattern');
        self::assertTrue($hasComments, 'Should detect comments pattern');
    }

    #[Test]
    public function it_includes_query_pattern_in_description(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 4', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Description should include normalized pattern
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();

        self::assertStringContainsString('Pattern:', $description);
        self::assertStringContainsString('SELECT', $description);
        self::assertStringContainsString('users', strtolower($description));
    }

    #[Test]
    public function it_includes_backtrace_from_first_query(): void
    {
        // Arrange: Add backtrace to first query
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace('SELECT * FROM users WHERE id = 1', [
                ['file' => 'UserRepository.php', 'line' => 42],
            ], 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 4', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should include backtrace from first query
        $issuesArray = $issues->toArray();
        $backtrace = $issuesArray[0]->getBacktrace();

        self::assertNotNull($backtrace);
        self::assertIsArray($backtrace);
        self::assertArrayHasKey('file', $backtrace[0]);
        self::assertEquals('UserRepository.php', $backtrace[0]['file']);
    }

    #[Test]
    public function it_normalizes_whitespace(): void
    {
        // Arrange: Same query with different whitespace
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 5.0)
            ->addQuery('SELECT   *   FROM   users   WHERE   id = 2', 5.0)
            ->addQuery("SELECT * \n FROM users \n WHERE id = 3", 5.0)
            ->addQuery("SELECT *\tFROM\tusers\tWHERE\tid = 4", 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should recognize all as same pattern
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should normalize whitespace');
        self::assertStringContainsString('6 queries', $issuesArray[0]->getTitle());
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
    public function it_handles_single_query(): void
    {
        // Arrange: Only one query
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect N+1
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_handles_all_different_queries(): void
    {
        // Arrange: All different query patterns
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users', 5.0)
            ->addQuery('SELECT * FROM posts', 5.0)
            ->addQuery('SELECT * FROM comments', 5.0)
            ->addQuery('INSERT INTO logs VALUES (1)', 5.0)
            ->addQuery('UPDATE settings SET value = 1', 5.0)
            ->addQuery('DELETE FROM cache WHERE id = 1', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect N+1 (all different patterns)
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_has_performance_category(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 4', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertEquals('performance', $issuesArray[0]->getCategory());
    }

    #[Test]
    public function it_shows_only_one_representative_query_instead_of_all_duplicates(): void
    {
        // Real-world scenario: 6 identical N+1 queries (like Sylius product variants)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM sylius_product_variant WHERE product_id = 1', 0.3)
            ->addQuery('SELECT * FROM sylius_product_variant WHERE product_id = 2', 0.3)
            ->addQuery('SELECT * FROM sylius_product_variant WHERE product_id = 3', 0.3)
            ->addQuery('SELECT * FROM sylius_product_variant WHERE product_id = 4', 0.3)
            ->addQuery('SELECT * FROM sylius_product_variant WHERE product_id = 5', 0.3)
            ->addQuery('SELECT * FROM sylius_product_variant WHERE product_id = 6', 0.3)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];

        // Title should mention 6 queries
        self::assertStringContainsString('6 queries', $issue->getTitle());

        // But queries array should contain only 1 representative example (not 6 duplicates)
        $issueData = $issue->getData();
        self::assertArrayHasKey('queries', $issueData);
        self::assertCount(1, $issueData['queries'], 'Should show only 1 representative query, not all 6 duplicates in profiler');
    }

    // ========== NEW TESTS FOR TYPE-AWARE N+1 DETECTION ==========

    #[Test]
    public function it_detects_proxy_n_plus_one_pattern(): void
    {
        // Arrange: Proxy N+1 pattern (ManyToOne/OneToOne) - WHERE id = ?
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 4', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect as proxy N+1
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $issue = $issuesArray[0];
        self::assertStringContainsString('proxy', strtolower($issue->getTitle()));
        self::assertStringContainsString('Proxy initialization', $issue->getDescription());

        // Should suggest Batch Fetch for proxy N+1
        $suggestion = $issue->getSuggestion();
        self::assertNotNull($suggestion);
        self::assertStringContainsString('Batch', $suggestion->getCode());
    }

    #[Test]
    public function it_detects_collection_n_plus_one_pattern(): void
    {
        // Arrange: Collection N+1 pattern (OneToMany/ManyToMany) - WHERE foreign_key_id = ?
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM posts WHERE user_id = 1', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 2', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 3', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 4', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 5', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 6', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect as collection N+1
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $issue = $issuesArray[0];
        self::assertStringContainsString('collection', strtolower($issue->getTitle()));

        // Should suggest Eager Loading for collection N+1 (no LIMIT)
        $suggestion = $issue->getSuggestion();
        self::assertNotNull($suggestion);
        self::assertStringContainsString('JOIN', $suggestion->getCode());
    }

    #[Test]
    public function it_detects_partial_collection_access_with_limit(): void
    {
        // Arrange: Collection N+1 with LIMIT (partial access) - suggests EXTRA_LAZY
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM posts WHERE user_id = 1 LIMIT 5', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 2 LIMIT 5', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 3 LIMIT 5', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 4 LIMIT 5', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 5 LIMIT 5', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 6 LIMIT 5', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect as partial collection access
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $issue = $issuesArray[0];
        self::assertStringContainsString('partial access', strtolower($issue->getDescription()));
        self::assertStringContainsString('EXTRA_LAZY', $issue->getDescription());

        // Should suggest Extra Lazy for partial collection access
        $suggestion = $issue->getSuggestion();
        self::assertNotNull($suggestion);
        self::assertStringContainsString('EXTRA_LAZY', $suggestion->getCode());
    }

    #[Test]
    public function it_applies_higher_severity_for_proxy_n_plus_one(): void
    {
        // Arrange: Create two similar scenarios - proxy vs collection
        // Both have 8 queries (just above warning threshold of 10 after multiplier for proxy)

        // Proxy N+1: WHERE id = ?
        $proxyQueries = QueryDataBuilder::create();
        for ($i = 1; $i <= 8; ++$i) {
            $proxyQueries->addQuery("SELECT * FROM users WHERE id = {$i}", 5.0);
        }

        // Collection N+1: WHERE foreign_key_id = ?
        $collectionQueries = QueryDataBuilder::create();
        for ($i = 1; $i <= 8; ++$i) {
            $collectionQueries->addQuery("SELECT * FROM posts WHERE user_id = {$i}", 5.0);
        }

        // Act
        $proxyIssues      = $this->analyzer->analyze($proxyQueries->build());
        $collectionIssues = $this->analyzer->analyze($collectionQueries->build());

        // Assert: Proxy should have higher severity due to 1.5x multiplier
        // 8 * 1.5 = 12 (warning), vs 8 (info)
        $proxyIssueArray      = $proxyIssues->toArray();
        $collectionIssueArray = $collectionIssues->toArray();

        self::assertCount(1, $proxyIssueArray);
        self::assertCount(1, $collectionIssueArray);

        $proxySeverity      = $proxyIssueArray[0]->getSeverity()->value;
        $collectionSeverity = $collectionIssueArray[0]->getSeverity()->value;

        // Proxy N+1 should be more severe (low vs info) due to 1.3x multiplier
        // 8 * 1.3 = 10.4 → LOW severity (10-14 range)
        self::assertEquals('warning', $proxySeverity, 'Proxy N+1 should be LOW with 8 queries (8*1.3=10.4)');
        self::assertEquals('info', $collectionSeverity, 'Collection N+1 should be INFO with 8 queries');
    }

    #[Test]
    public function it_creates_distinct_aggregation_keys_for_different_relations(): void
    {
        // Arrange: Two different relations with similar SQL structure
        $queries = QueryDataBuilder::create()
            // User->posts relation
            ->addQuery('SELECT * FROM posts WHERE user_id = 1', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 2', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 3', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 4', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 5', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 6', 5.0)
            // Post->comments relation (different foreign key, should be separate issue)
            ->addQuery('SELECT * FROM comments WHERE post_id = 1', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 2', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 3', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 4', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 5', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 6', 3.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect TWO distinct issues (not grouped together)
        $issuesArray = $issues->toArray();
        self::assertCount(2, $issuesArray, 'Should create separate issues for different relations');

        // Verify both tables are represented
        $hasPosts    = false;
        $hasComments = false;

        foreach ($issuesArray as $issue) {
            $description = strtolower($issue->getDescription());
            if (str_contains($description, 'posts')) {
                $hasPosts = true;
            }
            if (str_contains($description, 'comments')) {
                $hasComments = true;
            }
        }

        self::assertTrue($hasPosts, 'Should detect posts relation');
        self::assertTrue($hasComments, 'Should detect comments relation');
    }

    #[Test]
    public function it_includes_type_information_in_title(): void
    {
        // Arrange: Proxy N+1
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 4', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Title should include type (proxy/collection/unknown)
        $issuesArray = $issues->toArray();
        $title       = $issuesArray[0]->getTitle();

        self::assertMatchesRegularExpression('/\((?:proxy|collection|unknown)\)/i', $title, 'Title should include N+1 type');
    }

    // ========== NEW TESTS FOR SINGLE-RECORD EXEMPTION ==========

    #[Test]
    public function it_exempts_queries_with_limit_1(): void
    {
        self::markTestSkipped('Single-record exemption needs more careful implementation - disabled for now to avoid false negatives');
    }

    #[Test]
    public function it_exempts_simple_primary_key_lookups(): void
    {
        self::markTestSkipped('Single-record exemption needs more careful implementation - disabled for now to avoid false negatives');
    }

    #[Test]
    public function it_does_not_exempt_collection_queries(): void
    {
        // Arrange: Collection N+1 with foreign key (NOT primary key)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM posts WHERE user_id = 1', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 2', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 3', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 4', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 5', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 6', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT be exempted (collection N+1)
        self::assertCount(1, $issues, 'Collection N+1 should NOT be exempted');
    }

    // ========== NEW TESTS FOR 5-LEVEL SEVERITY CLASSIFICATION ==========

    #[Test]
    public function it_assigns_low_severity_for_10_to_14_queries(): void
    {
        // Arrange: 12 queries (LOW severity threshold)
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 12; ++$i) {
            $queries->addQuery("SELECT * FROM posts WHERE user_id = {$i}", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should be LOW severity (10-14 queries)
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertEquals('warning', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_assigns_medium_severity_for_15_to_19_queries(): void
    {
        // Arrange: 17 queries (MEDIUM severity threshold)
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 17; ++$i) {
            $queries->addQuery("SELECT * FROM posts WHERE user_id = {$i}", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should be MEDIUM severity (15-19 queries)
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertEquals('warning', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_assigns_critical_severity_for_20_plus_queries(): void
    {
        // Arrange: 25 queries (CRITICAL severity threshold in 3-level system)
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 25; ++$i) {
            $queries->addQuery("SELECT * FROM posts WHERE user_id = {$i}", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should be CRITICAL severity (>= 20 queries)
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertEquals('critical', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_assigns_critical_severity_for_30_plus_queries(): void
    {
        // Arrange: 35 queries (CRITICAL severity threshold)
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 35; ++$i) {
            $queries->addQuery("SELECT * FROM posts WHERE user_id = {$i}", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should be CRITICAL severity (30+ queries)
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertEquals('critical', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_uses_3_level_severity_with_proxy_multiplier(): void
    {
        // Arrange: 16 proxy queries with 1.3x multiplier = 20.8 → CRITICAL (3-level system)
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 16; ++$i) {
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: 16 * 1.3 = 20.8 → CRITICAL severity (proxy multiplier applied, >= 20 threshold)
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertEquals('critical', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_excludes_insert_queries_from_n_plus_one_detection(): void
    {
        // Arrange: 10 INSERT queries (flush in loop pattern - should NOT be detected as N+1)
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 10; ++$i) {
            $queries->addQuery(
                "INSERT INTO orders (id, status, total, created_at, user_id, customer_id) VALUES ({$i}, 'pending', 100.0, '2025-01-01', 1, 1)",
                5.0,
            );
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT detect INSERT queries as N+1 (this is flush-in-loop, not N+1)
        $issuesArray = $issues->toArray();
        self::assertCount(0, $issuesArray, 'INSERT queries should not be detected as N+1');
    }

    #[Test]
    public function it_excludes_update_queries_from_n_plus_one_detection(): void
    {
        // Arrange: 10 UPDATE queries (bulk update pattern - should NOT be detected as N+1)
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 10; ++$i) {
            $queries->addQuery(
                "UPDATE orders SET status = 'processed' WHERE id = {$i}",
                5.0,
            );
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT detect UPDATE queries as N+1
        $issuesArray = $issues->toArray();
        self::assertCount(0, $issuesArray, 'UPDATE queries should not be detected as N+1');
    }

    #[Test]
    public function it_excludes_delete_queries_from_n_plus_one_detection(): void
    {
        // Arrange: 10 DELETE queries (should NOT be detected as N+1)
        $queries = QueryDataBuilder::create();
        for ($i = 1; $i <= 10; ++$i) {
            $queries->addQuery(
                "DELETE FROM orders WHERE id = {$i}",
                5.0,
            );
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT detect DELETE queries as N+1
        $issuesArray = $issues->toArray();
        self::assertCount(0, $issuesArray, 'DELETE queries should not be detected as N+1');
    }
}
