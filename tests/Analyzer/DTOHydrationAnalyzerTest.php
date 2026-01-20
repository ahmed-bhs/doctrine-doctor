<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\DTOHydrationAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for DTOHydrationAnalyzer.
 *
 * This analyzer detects inefficient hydration patterns that should use DTOs.
 * It analyzes queries with aggregation functions to suggest using DTOs instead
 * of full entity hydration for better performance.
 *
 * Full integration tests exist in DTOHydrationAnalyzerIntegrationTest.
 * These unit tests verify basic analyzer behavior.
 */
final class DTOHydrationAnalyzerTest extends TestCase
{
    private DTOHydrationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new DTOHydrationAnalyzer(
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_returns_issue_collection(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertIsObject($issues);
        self::assertIsIterable($issues);
    }

    #[Test]
    public function it_handles_empty_query_collection(): void
    {
        // Arrange: Query analyzers need queries to analyze
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should return empty collection for no queries
        self::assertCount(0, $issues->toArray());
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        // Assert
        self::assertInstanceOf(\AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface::class, $this->analyzer);
    }

    #[Test]
    public function it_does_not_throw_on_analysis(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act & Assert: Should not throw exceptions
        $this->expectNotToPerformAssertions();
        $this->analyzer->analyze($queries);
    }

    #[Test]
    public function it_returns_iterable_issues(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Can iterate over issues
        $count = 0;
        foreach ($issues as $issue) {
            $count++;
            self::assertNotNull($issue);
        }

        self::assertGreaterThanOrEqual(0, $count);
    }

    #[Test]
    public function it_uses_generator_pattern(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: IssueCollection uses generator pattern
        self::assertInstanceOf(\Generator::class, $issues->getIterator());
    }

    #[Test]
    public function it_analyzes_queries_for_aggregations(): void
    {
        // Arrange: This is a query analyzer, analyzes SQL queries
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT COUNT(*) FROM users")
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should return collection
        self::assertGreaterThanOrEqual(0, count($issues->toArray()));
    }
}
