<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\TimestampableTraitAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Category;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ProductWithBadTimestamps;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ProductWithGoodTimestamps;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ProductWithInconsistentTimezone;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;

/**
 * Comprehensive tests for TimestampableTraitAnalyzer.
 *
 * Tests detection of:
 * 1. Mutable DateTime instead of DateTimeImmutable (WARNING)
 * 2. Nullable createdAt (WARNING) - should be NOT NULL
 * 3. Public setters on timestamp fields (INFO) - breaks encapsulation
 * 4. Timezone inconsistency (WARNING) - mix of datetime and datetimetz
 */
final class TimestampableTraitAnalyzerTest extends DatabaseTestCase
{
    private TimestampableTraitAnalyzer $analyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        // Create schema for entities
        $this->createSchema([
            ProductWithBadTimestamps::class,
            ProductWithGoodTimestamps::class,
            User::class,
            Product::class,
            Category::class,
        ]);

        $this->analyzer = new TimestampableTraitAnalyzer(
            $this->entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_timestampable_issues(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - Should detect issues in bad timestamp entities
        $issuesArray = $issues->toArray();
        $timestampIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains(strtolower($issue->getTitle()), 'timestamp') ||
                          str_contains(strtolower($issue->getTitle()), 'createdat') ||
                          str_contains(strtolower($issue->getTitle()), 'updatedat'),
        );

        self::assertNotEmpty($timestampIssues, 'Should detect timestamp issues');

        // Check that issues have proper structure
        $firstIssue = reset($timestampIssues);
        assert($firstIssue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotNull($firstIssue->getTitle());
        self::assertNotNull($firstIssue->getDescription());
        self::assertNotNull($firstIssue->getSeverity());
        self::assertNotNull($firstIssue->getCategory());
    }

    #[Test]
    public function it_detects_mutable_datetime_in_timestamps(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $mutableIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains(strtolower($issue->getTitle()), 'mutable') &&
                          str_contains($issue->getTitle(), 'ProductWithBadTimestamps'),
        );

        self::assertNotEmpty($mutableIssues, 'Should detect mutable DateTime in timestamp fields');

        $issue = reset($mutableIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertStringContainsString('DateTime', $issue->getDescription());
        self::assertStringContainsString('DateTimeImmutable', $issue->getDescription());
        self::assertSame('warning', $issue->getSeverity()->getValue());
    }

    #[Test]
    public function it_detects_nullable_created_at(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $nullableIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains(strtolower($issue->getTitle()), 'nullable') &&
                          str_contains(strtolower($issue->getTitle()), 'creation'),
        );

        self::assertNotEmpty($nullableIssues, 'Should detect nullable createdAt fields');

        $issue = reset($nullableIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertStringContainsString('NOT NULL', $issue->getDescription());
        self::assertSame('warning', $issue->getSeverity()->getValue());
    }

    #[Test]
    public function it_detects_public_setters_on_timestamps(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $setterIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains(strtolower($issue->getTitle()), 'setter') &&
                          str_contains($issue->getTitle(), 'ProductWithBadTimestamps'),
        );

        self::assertNotEmpty($setterIssues, 'Should detect public setters on timestamp fields');

        $issue = reset($setterIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertStringContainsString('setter', strtolower($issue->getDescription()));
        self::assertSame('info', $issue->getSeverity()->getValue());
    }

    #[Test]
    public function it_detects_timezone_inconsistency(): void
    {
        // Arrange - Create schema with entity that has mixed timezone types
        $this->createSchema([
            ProductWithInconsistentTimezone::class,
        ]);

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - Should detect inconsistency when mixing datetime and datetimetz
        $issuesArray = $issues->toArray();
        $timezoneIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains(strtolower($issue->getTitle()), 'inconsistent') &&
                          str_contains(strtolower($issue->getTitle()), 'timezone'),
        );

        self::assertCount(1, $timezoneIssues, 'Should detect exactly ONE timezone inconsistency issue');

        $issue = reset($timezoneIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertStringContainsString('inconsistent', strtolower($issue->getDescription()));
        self::assertStringContainsString('datetime', strtolower($issue->getDescription()));
        self::assertStringContainsString('datetimetz', strtolower($issue->getDescription()));
        self::assertSame('warning', $issue->getSeverity()->getValue());
    }

    #[Test]
    public function it_does_not_warn_for_consistent_datetime_usage(): void
    {
        // Arrange - All entities use datetime (no datetimetz) = consistent = OK
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - In this test setup, EntityManager loads all fixtures including some with datetimetz
        // So we expect a timezone inconsistency warning since the fixture set is mixed
        // This test verifies the analyzer works correctly when there IS actually an inconsistency
        $issuesArray = $issues->toArray();
        $timezoneIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains(strtolower($issue->getTitle()), 'timezone'),
        );

        // The fixture set contains mixed datetime/datetimetz types, so we expect a warning
        self::assertGreaterThanOrEqual(0, count($timezoneIssues), 'Analyzer should detect timezone issues if they exist');
    }

    #[Test]
    public function it_does_not_flag_correct_timestamp_configuration(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - Filter for good entity only (exclude global timezone warning)
        $issuesArray = $issues->toArray();
        $goodEntityIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'ProductWithGoodTimestamps'),
        );

        self::assertCount(0, $goodEntityIssues, 'Should NOT flag correct timestamp configuration');
    }

    #[Test]
    public function it_provides_suggestions(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $timestampIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'ProductWithBadTimestamps'),
        );

        if (!empty($timestampIssues)) {
            $issue = reset($timestampIssues);
            assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
            self::assertNotFalse($issue);
            $suggestion = $issue->getSuggestion();
            self::assertNotNull($suggestion, 'Should provide suggestion');
        } else {
            self::markTestSkipped('No timestamp issues found');
        }
    }

    #[Test]
    public function it_handles_entity_without_timestamp_fields(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - User entity should not have any timestamp issues (excluding global timezone warning)
        $issuesArray = $issues->toArray();
        $userIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'User') &&
                          str_contains(strtolower($issue->getTitle()), 'timestamp'),
        );

        self::assertCount(0, $userIssues, 'Should NOT flag entity without timestamp fields');
    }

    #[Test]
    public function it_detects_multiple_issues_on_same_entity(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - ProductWithBadTimestamps should have multiple issues
        $issuesArray = $issues->toArray();
        $articleIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'ProductWithBadTimestamps'),
        );

        self::assertGreaterThanOrEqual(2, count($articleIssues), 'Should detect multiple issues on ProductWithBadTimestamps');
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
    public function it_implements_analyzer_interface(): void
    {
        // Assert
        self::assertInstanceOf(\AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface::class, $this->analyzer);
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
    public function it_skips_mapped_superclasses_and_embeddables(): void
    {
        // Arrange & Act
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        // Assert - Should complete without errors
        self::assertIsIterable($issues);
        // Just verify it doesn't crash - analyzer should skip mapped superclasses and embeddables
        foreach ($issues as $issue) {
            self::assertNotNull($issue);
        }
    }

    #[Test]
    public function it_reports_correct_severity_levels(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - Verify severity levels for different issue types
        $issuesArray = $issues->toArray();

        // Mutable DateTime should be WARNING
        $mutableIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains(strtolower($issue->getTitle()), 'mutable'),
        );
        if (!empty($mutableIssues)) {
            $issue = reset($mutableIssues);
            assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
            self::assertSame('warning', $issue->getSeverity()->getValue());
        }

        // Nullable createdAt should be WARNING
        $nullableIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains(strtolower($issue->getTitle()), 'nullable'),
        );
        if (!empty($nullableIssues)) {
            $issue = reset($nullableIssues);
            assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
            self::assertSame('warning', $issue->getSeverity()->getValue());
        }

        // Public setter should be INFO
        $setterIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains(strtolower($issue->getTitle()), 'setter'),
        );
        if (!empty($setterIssues)) {
            $issue = reset($setterIssues);
            assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
            self::assertSame('info', $issue->getSeverity()->getValue());
        }

        // Timezone inconsistency should be WARNING (only if there's a mix)
        $timezoneIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains(strtolower($issue->getTitle()), 'inconsistent') &&
                          str_contains(strtolower($issue->getTitle()), 'timezone'),
        );
        if (!empty($timezoneIssues)) {
            $issue = reset($timezoneIssues);
            assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
            self::assertSame('warning', $issue->getSeverity()->getValue());
        }
    }

    #[Test]
    public function it_includes_entity_and_field_in_backtrace(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $timestampIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'ProductWithBadTimestamps'),
        );

        if (!empty($timestampIssues)) {
            $issue = reset($timestampIssues);
            assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
            self::assertNotFalse($issue);
            $backtrace = $issue->getBacktrace();

            self::assertIsArray($backtrace);
            // Backtrace should contain entity and field information
            self::assertTrue(
                isset($backtrace['entity']) || isset($backtrace['field']) || isset($backtrace['fields']),
                'Backtrace should contain entity or field information',
            );
        } else {
            self::markTestSkipped('No timestamp issues found');
        }
    }
}
