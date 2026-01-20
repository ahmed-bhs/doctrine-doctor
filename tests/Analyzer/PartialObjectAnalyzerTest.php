<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\PartialObjectAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use Generator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for PartialObjectAnalyzer.
 *
 * Verifies that the analyzer correctly detects queries loading full entities
 * when partial objects or array hydration would be more efficient.
 */
final class PartialObjectAnalyzerTest extends TestCase
{
    private PartialObjectAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new PartialObjectAnalyzer(threshold: 5);
    }

    #[Test]
    public function it_detects_full_entity_queries_above_threshold(): void
    {
        // Arrange: 6 identical full entity queries (above threshold of 5)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u FROM User u WHERE u.status = ?', 10.0)
            ->addQuery('SELECT u FROM User u WHERE u.status = ?', 11.0)
            ->addQuery('SELECT u FROM User u WHERE u.status = ?', 12.0)
            ->addQuery('SELECT u FROM User u WHERE u.status = ?', 13.0)
            ->addQuery('SELECT u FROM User u WHERE u.status = ?', 14.0)
            ->addQuery('SELECT u FROM User u WHERE u.status = ?', 15.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);

        $issue = $issues->first();
        self::assertInstanceOf(PerformanceIssue::class, $issue);
        self::assertStringContainsString('6 queries loading full entities', $issue->getDescription());
        self::assertStringContainsString('partial objects', $issue->getDescription());
    }

    #[Test]
    public function it_does_not_detect_queries_below_threshold(): void
    {
        // Arrange: Only 4 identical queries (below threshold of 5)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u FROM User u WHERE u.status = ?', 10.0)
            ->addQuery('SELECT u FROM User u WHERE u.status = ?', 11.0)
            ->addQuery('SELECT u FROM User u WHERE u.status = ?', 12.0)
            ->addQuery('SELECT u FROM User u WHERE u.status = ?', 13.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_partial_object_queries(): void
    {
        // Arrange: Queries already using PARTIAL (no issue)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT PARTIAL u.{id, username} FROM User u', 10.0)
            ->addQuery('SELECT PARTIAL u.{id, username} FROM User u', 11.0)
            ->addQuery('SELECT PARTIAL u.{id, username} FROM User u', 12.0)
            ->addQuery('SELECT PARTIAL u.{id, username} FROM User u', 13.0)
            ->addQuery('SELECT PARTIAL u.{id, username} FROM User u', 14.0)
            ->addQuery('SELECT PARTIAL u.{id, username} FROM User u', 15.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_array_hydration_queries(): void
    {
        // Arrange: Queries selecting specific fields (array hydration pattern)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u.id, u.username, u.email FROM User u', 10.0)
            ->addQuery('SELECT u.id, u.username, u.email FROM User u', 11.0)
            ->addQuery('SELECT u.id, u.username, u.email FROM User u', 12.0)
            ->addQuery('SELECT u.id, u.username, u.email FROM User u', 13.0)
            ->addQuery('SELECT u.id, u.username, u.email FROM User u', 14.0)
            ->addQuery('SELECT u.id, u.username, u.email FROM User u', 15.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_write_operations(): void
    {
        // Arrange: Mix of full entity reads and write operations
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u FROM User u WHERE u.status = ?', 10.0)
            ->addQuery('UPDATE User u SET u.status = ? WHERE u.id = ?', 5.0)
            ->addQuery('DELETE FROM User u WHERE u.id = ?', 3.0)
            ->addQuery('INSERT INTO User (name) VALUES (?)', 2.0)
            ->addQuery('SELECT u FROM User u WHERE u.status = ?', 11.0)
            ->addQuery('SELECT u FROM User u WHERE u.status = ?', 12.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should not count write operations, only 3 SELECT queries (below threshold)
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_handles_empty_query_collection(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_requires_minimum_query_count(): void
    {
        // Arrange: Only 2 queries (below MIN_QUERY_COUNT of 3)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u FROM User u', 10.0)
            ->addQuery('SELECT u FROM User u', 11.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_provides_correct_severity_for_moderate_usage(): void
    {
        // Arrange: 8 queries (moderate usage)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT p FROM Product p', 10.0)
            ->addQuery('SELECT p FROM Product p', 11.0)
            ->addQuery('SELECT p FROM Product p', 12.0)
            ->addQuery('SELECT p FROM Product p', 13.0)
            ->addQuery('SELECT p FROM Product p', 14.0)
            ->addQuery('SELECT p FROM Product p', 15.0)
            ->addQuery('SELECT p FROM Product p', 16.0)
            ->addQuery('SELECT p FROM Product p', 17.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);

        $issue = $issues->first();
        self::assertNotNull($issue);
        self::assertSame('warning', $issue->getSeverity()->getValue());
    }

    #[Test]
    public function it_provides_correct_severity_for_heavy_usage(): void
    {
        // Arrange: 15 queries (heavy usage - above 10)
        $queryBuilder = QueryDataBuilder::create();
        for ($i = 0; $i < 15; $i++) {
            $queryBuilder->addQuery('SELECT o FROM Order o', 10.0 + $i);
        }
        $queries = $queryBuilder->build();

        // Act
        $issues = $this->analyzer->analyze($queries);
        self::assertNotNull($issues);

        // Assert
        self::assertCount(1, $issues);

        $issue = $issues->first();
        self::assertNotNull($issue);
        self::assertSame('critical', $issue->getSeverity()->getValue());
    }

    #[Test]
    public function it_groups_queries_by_pattern(): void
    {
        // Arrange: Two different query patterns, each above threshold
        $queries = QueryDataBuilder::create()
            // Pattern 1: User queries (6 times)
            ->addQuery('SELECT u FROM User u WHERE u.id = 1', 10.0)
            ->addQuery('SELECT u FROM User u WHERE u.id = 2', 11.0)
            ->addQuery('SELECT u FROM User u WHERE u.id = 3', 12.0)
            ->addQuery('SELECT u FROM User u WHERE u.id = 4', 13.0)
            ->addQuery('SELECT u FROM User u WHERE u.id = 5', 14.0)
            ->addQuery('SELECT u FROM User u WHERE u.id = 6', 15.0)
            // Pattern 2: Product queries (6 times)
            ->addQuery('SELECT p FROM Product p WHERE p.id = 1', 20.0)
            ->addQuery('SELECT p FROM Product p WHERE p.id = 2', 21.0)
            ->addQuery('SELECT p FROM Product p WHERE p.id = 3', 22.0)
            ->addQuery('SELECT p FROM Product p WHERE p.id = 4', 23.0)
            ->addQuery('SELECT p FROM Product p WHERE p.id = 5', 24.0)
            ->addQuery('SELECT p FROM Product p WHERE p.id = 6', 25.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect 2 separate issues (one for each pattern)
        self::assertCount(2, $issues);
    }

    #[Test]
    public function it_uses_generator_pattern(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u FROM User u', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertInstanceOf(IssueCollection::class, $issues);
        self::assertInstanceOf(Generator::class, $issues->getIterator());
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        // Assert
        self::assertInstanceOf(\AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface::class, $this->analyzer);
    }

    #[Test]
    public function it_has_descriptive_name_and_description(): void
    {
        // Assert
        self::assertSame('Partial Object Analyzer', $this->analyzer->getName());
        self::assertStringContainsString('partial objects', $this->analyzer->getDescription());
        self::assertStringContainsString('array hydration', $this->analyzer->getDescription());
    }

    #[Test]
    public function it_provides_suggestion_with_alternatives(): void
    {
        // Arrange: 6 queries above threshold
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u FROM User u', 10.0)
            ->addQuery('SELECT u FROM User u', 11.0)
            ->addQuery('SELECT u FROM User u', 12.0)
            ->addQuery('SELECT u FROM User u', 13.0)
            ->addQuery('SELECT u FROM User u', 14.0)
            ->addQuery('SELECT u FROM User u', 15.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);
        self::assertNotNull($issues);

        // Assert
        self::assertCount(1, $issues);

        $issue = $issues->first();
        self::assertNotFalse($issue);
        self::assertNotNull($issue);
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
        /** @var \AhmedBhs\DoctrineDoctor\Suggestion\StructuredSuggestion $suggestion */
        self::assertStringContainsString('Partial Objects', $suggestion->getTitle());
        self::assertStringContainsString('Array Hydration', $suggestion->getTitle());
    }

    #[Test]
    public function it_detects_select_star_queries(): void
    {
        // Arrange: SELECT * queries
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE status = ?', 10.0)
            ->addQuery('SELECT * FROM users WHERE status = ?', 11.0)
            ->addQuery('SELECT * FROM users WHERE status = ?', 12.0)
            ->addQuery('SELECT * FROM users WHERE status = ?', 13.0)
            ->addQuery('SELECT * FROM users WHERE status = ?', 14.0)
            ->addQuery('SELECT * FROM users WHERE status = ?', 15.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_respects_custom_threshold(): void
    {
        // Arrange: Custom threshold of 3
        $analyzer = new PartialObjectAnalyzer(threshold: 3);

        // Only 3 queries (exactly at threshold)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u FROM User u', 10.0)
            ->addQuery('SELECT u FROM User u', 11.0)
            ->addQuery('SELECT u FROM User u', 12.0)
            ->build();

        // Act
        $issues = $analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
    }
}
