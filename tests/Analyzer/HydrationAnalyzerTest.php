<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\HydrationAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for HydrationAnalyzer.
 *
 * This analyzer detects excessive hydration issues when queries return too many rows.
 */
final class HydrationAnalyzerTest extends TestCase
{
    private HydrationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new HydrationAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            rowThreshold: 99,
            criticalThreshold: 999,
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_no_queries(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_returns_empty_collection_for_small_result_sets(): void
    {
        // Arrange: Queries with small LIMIT (below threshold of 99)
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users LIMIT 10")
            ->addQuery("SELECT * FROM users LIMIT 50")
            ->addQuery("SELECT * FROM users LIMIT 99")
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_excessive_hydration_from_limit_clause(): void
    {
        // Arrange: Query with LIMIT > 99
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users LIMIT 150")
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertStringContainsString('Excessive Hydration', $issue->getTitle());
        self::assertStringContainsString('150 rows', $issue->getTitle());
        self::assertStringContainsString('hydration overhead', $issue->getDescription());
    }

    #[Test]
    public function it_sets_severity_to_warning_for_moderate_result_sets(): void
    {
        // Arrange: Row count > threshold but < critical
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users LIMIT 500")
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertEquals('warning', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_sets_severity_to_critical_for_large_result_sets(): void
    {
        // Arrange: Row count > criticalThreshold (999)
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users LIMIT 5000")
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertEquals('critical', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_limit_with_offset_syntax(): void
    {
        // Arrange: LIMIT with OFFSET
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users LIMIT 200 OFFSET 100")
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertStringContainsString('200 rows', $issue->getTitle());
    }

    #[Test]
    public function it_detects_limit_with_comma_syntax(): void
    {
        // Arrange: LIMIT offset, limit syntax (MySQL style)
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users LIMIT 100, 300")
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertStringContainsString('300 rows', $issue->getTitle());
    }

    #[Test]
    public function it_handles_case_insensitive_limit(): void
    {
        // Arrange: lowercase limit
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users limit 150")
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_ignores_queries_without_limit_and_no_row_count(): void
    {
        // Arrange: Query without LIMIT and no rowCount metadata
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users")
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Cannot estimate, should skip
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_multiple_excessive_queries(): void
    {
        // Arrange: Multiple queries exceeding threshold
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users LIMIT 150")
            ->addQuery("SELECT * FROM posts LIMIT 200")
            ->addQuery("SELECT * FROM comments LIMIT 500")
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(3, $issues);
    }

    #[Test]
    public function it_provides_suggestion_for_excessive_hydration(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users LIMIT 500")
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();
        self::assertNotNull($suggestion);
    }

    #[Test]
    public function it_includes_threshold_in_description(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users LIMIT 150")
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issue = $issues->toArray()[0];
        self::assertStringContainsString('99', $issue->getDescription());
        self::assertStringContainsString('threshold', $issue->getDescription());
    }

    #[Test]
    public function it_handles_limit_at_exact_threshold_boundary(): void
    {
        // Arrange: Exactly at threshold (should NOT trigger)
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users LIMIT 99")
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_handles_limit_just_above_threshold(): void
    {
        // Arrange: Just above threshold (should trigger)
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users LIMIT 100")
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_handles_complex_queries_with_joins(): void
    {
        // Arrange: Complex query with LIMIT
        $queries = QueryDataBuilder::create()
            ->addQuery(
                "SELECT u.*, p.* FROM users u " .
                "LEFT JOIN posts p ON u.id = p.user_id " .
                "WHERE u.status = 'active' " .
                "LIMIT 250",
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertStringContainsString('250 rows', $issue->getTitle());
    }
}
