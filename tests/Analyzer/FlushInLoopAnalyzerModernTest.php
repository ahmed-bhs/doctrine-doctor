<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\FlushInLoopAnalyzerModern;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for FlushInLoopAnalyzerModern.
 *
 * This is the MODERN version using SuggestionFactory for better separation of concerns.
 * Detection logic is identical to FlushInLoopAnalyzer, but uses the new architecture.
 */
final class FlushInLoopAnalyzerModernTest extends TestCase
{
    private FlushInLoopAnalyzerModern $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new FlushInLoopAnalyzerModern(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            5,  // flushCountThreshold: 5 flushes to trigger detection
        );
    }

    #[Test]
    public function it_detects_flush_in_loop_pattern(): void
    {
        // Arrange: Pattern of INSERT -> SELECT repeated 6 times (simulates flush in loop)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 6; $i++) {
            $queries->addQuery("INSERT INTO users (name) VALUES ('User {$i}')", 2.0);
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 1.0);  // Simulates flush boundary
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect flush in loop
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should detect flush in loop pattern');

        $issue = $issuesArray[0];
        self::assertStringContainsString('flush()', $issue->getTitle());
        self::assertStringContainsString('5', $issue->getTitle());  // Counts flush groups (N-1)
    }

    #[Test]
    public function it_does_not_detect_below_threshold(): void
    {
        // Arrange: Only 4 flush patterns (below threshold of 5)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 4; $i++) {
            $queries->addQuery("INSERT INTO users (name) VALUES ('User {$i}')", 2.0);
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT detect (below threshold)
        self::assertCount(0, $issues, 'Should not detect below threshold');
    }

    #[Test]
    public function it_detects_update_followed_by_select(): void
    {
        // Arrange: UPDATE -> SELECT pattern (also indicates flush)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 6; $i++) {
            $queries->addQuery("UPDATE users SET status = 'active' WHERE id = {$i}", 2.0);
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect UPDATE -> SELECT pattern
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
    }

    #[Test]
    public function it_detects_with_backtrace_changes(): void
    {
        // Arrange: Backtrace changes indicate flush boundaries (loop iterations)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 6; $i++) {
            $queries->addQueryWithBacktrace(
                "INSERT INTO users (name) VALUES ('User')",
                [['file' => 'UserService.php', 'line' => 100 + $i]],  // Different line each iteration
                2.0,
            );
            $queries->addQueryWithBacktrace(
                "SELECT * FROM users WHERE id = ?",
                [['file' => 'UserService.php', 'line' => 101 + $i]],
                1.0,
            );
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect based on backtrace changes
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect with backtrace changes');
    }

    #[Test]
    public function it_calculates_average_operations_between_flush(): void
    {
        // Arrange: 1 operation between each flush
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 6; $i++) {
            $queries->addQuery("INSERT INTO users (name) VALUES ('User {$i}')", 2.0);
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should mention average operations
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();

        self::assertStringContainsString('1.0', $description, 'Should show avg operations');
    }

    #[Test]
    public function it_requires_small_operations_between_flush(): void
    {
        // Arrange: Too many operations between flushes (>10)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 6; $i++) {
            // 15 operations before each flush
            for ($j = 1; $j <= 15; $j++) {
                $queries->addQuery("INSERT INTO users (name) VALUES ('User {$j}')", 2.0);
            }
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT detect (avg operations > 10)
        self::assertCount(0, $issues, 'Should not detect when avg operations > 10');
    }

    #[Test]
    public function it_uses_suggestion_factory(): void
    {
        // Arrange: Test that modern version uses SuggestionFactory
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 6; $i++) {
            $queries->addQuery("INSERT INTO users (name) VALUES ('User {$i}')", 2.0);
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should use suggestion factory (suggestion should exist and have metadata)
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $suggestion = $issuesArray[0]->getSuggestion();
        self::assertNotNull($suggestion, 'Should provide suggestion from factory');

        // Verify suggestion has proper structure from factory
        self::assertNotNull($suggestion->getMetadata(), 'Should have metadata from factory');
        self::assertNotEmpty($suggestion->getCode(), 'Should have code from factory');
    }

    #[Test]
    public function it_gets_severity_from_suggestion(): void
    {
        // Arrange: Modern version gets severity from suggestion metadata
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 6; $i++) {
            $queries->addQuery("INSERT INTO users (name) VALUES ('User {$i}')", 2.0);
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Severity should come from suggestion metadata
        $issuesArray = $issues->toArray();
        $issue = $issuesArray[0];

        self::assertNotNull($issue->getSeverity());
        $suggestion = $issue->getSuggestion();
        self::assertNotNull($suggestion);
        self::assertEquals(
            $suggestion->getMetadata()->severity,
            $issue->getSeverity(),
            'Issue severity should match suggestion metadata severity',
        );
    }

    #[Test]
    public function it_includes_backtrace_in_issue(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 6; $i++) {
            $queries->addQueryWithBacktrace(
                "INSERT INTO users (name) VALUES ('User {$i}')",
                [['file' => 'UserService.php', 'line' => 100]],
                2.0,
            );
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should include backtrace
        $issuesArray = $issues->toArray();
        $backtrace = $issuesArray[0]->getBacktrace();

        self::assertNotNull($backtrace);
        self::assertIsArray($backtrace);
        self::assertEquals('UserService.php', $backtrace[0]['file']);
    }

    #[Test]
    public function it_limits_queries_to_20_in_issue(): void
    {
        // Arrange: 50 flush patterns
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 50; $i++) {
            $queries->addQuery("INSERT INTO users (name) VALUES ('User {$i}')", 2.0);
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should limit to 20 queries
        $issuesArray = $issues->toArray();
        $issue = $issuesArray[0];

        self::assertStringContainsString('49', $issue->getTitle());  // Counts flush groups (N-1)
        self::assertLessThanOrEqual(20, count($issue->getQueries()));
    }

    #[Test]
    public function it_mentions_performance_degradation(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 6; $i++) {
            $queries->addQuery("INSERT INTO users (name) VALUES ('User {$i}')", 2.0);
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Description should mention performance issues
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();

        self::assertStringContainsString('performance', strtolower($description));
    }

    #[Test]
    public function it_includes_threshold_in_description(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 6; $i++) {
            $queries->addQuery("INSERT INTO users (name) VALUES ('User {$i}')", 2.0);
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should mention threshold
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();

        self::assertStringContainsString('threshold', strtolower($description));
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
    public function it_ignores_only_selects(): void
    {
        // Arrange: Only SELECT queries (no INSERT/UPDATE/DELETE)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 10; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT detect (no write operations)
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_single_batch_operation(): void
    {
        // Arrange: All INSERTs followed by one SELECT (proper batching)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 100; $i++) {
            $queries->addQuery("INSERT INTO users (name) VALUES ('User {$i}')", 2.0);
        }
        $queries->addQuery("SELECT COUNT(*) FROM users", 1.0);

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should NOT detect (proper batching pattern)
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_has_performance_category(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 6; $i++) {
            $queries->addQuery("INSERT INTO users (name) VALUES ('User {$i}')", 2.0);
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert
        $issuesArray = $issues->toArray();
        self::assertEquals('performance', $issuesArray[0]->getCategory());
    }

    #[Test]
    public function it_detects_mixed_operations_pattern(): void
    {
        // Arrange: Mix of INSERT, UPDATE (both are write operations followed by SELECT)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 6; $i++) {
            if (0 === $i % 2) {
                $queries->addQuery("INSERT INTO users (name) VALUES ('User {$i}')", 2.0);
            } else {
                $queries->addQuery("UPDATE users SET status = 'active' WHERE id = {$i}", 2.0);
            }
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect mixed operations
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
    }

    #[Test]
    public function it_detects_multiple_operations_per_iteration(): void
    {
        // Arrange: 2 operations between each flush
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 6; $i++) {
            $queries->addQuery("INSERT INTO users (name) VALUES ('User {$i}')", 2.0);
            $queries->addQuery("UPDATE users SET created_at = NOW() WHERE id = {$i}", 1.0);
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should detect and show avg 2.0 operations
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $description = $issuesArray[0]->getDescription();
        self::assertStringContainsString('2.0', $description);
    }

    #[Test]
    public function it_uses_generator_pattern(): void
    {
        // Arrange: Test that modern version uses generator (IssueCollection::fromGenerator)
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 6; $i++) {
            $queries->addQuery("INSERT INTO users (name) VALUES ('User {$i}')", 2.0);
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 1.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should work with generator pattern (returns IssueCollection)
        self::assertInstanceOf(\AhmedBhs\DoctrineDoctor\Collection\IssueCollection::class, $issues);
        self::assertCount(1, $issues);
    }
}
