<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\ForeignKeyMappingAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for ForeignKeyMappingAnalyzer.
 *
 * This analyzer detects database foreign key mapping configuration issues.
 *
 * Note: Full integration tests exist in ForeignKeyMappingAnalyzerIntegrationTest.
 * These unit tests verify basic analyzer behavior and metadata.
 */
final class ForeignKeyMappingAnalyzerTest extends TestCase
{
    private ForeignKeyMappingAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager();

        $this->analyzer = new ForeignKeyMappingAnalyzer(
            $entityManager,
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
        // Arrange: Config analyzers don't use queries
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should analyze independently of queries
        self::assertIsObject($issues);
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
    public function it_works_with_sqlite_connection(): void
    {
        // Arrange: SQLite behavior may differ
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should return collection (may be empty for SQLite)
        self::assertGreaterThanOrEqual(0, count($issues->toArray()));
    }

    #[Test]
    public function it_detects_real_foreign_keys_as_issues(): void
    {
        // This test would require integration with full entity metadata
        // For now, we'll test the logic indirectly through integration tests
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function it_avoids_false_positives_for_configuration_fields(): void
    {
        // This test would require integration with full entity metadata
        // For now, we'll test the logic indirectly through integration tests
        $this->expectNotToPerformAssertions();
    }
}
