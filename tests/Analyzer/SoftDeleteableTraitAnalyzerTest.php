<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\SoftDeleteableTraitAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ArticleWithMutableDateTime;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Category;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CommentWithPublicSetter;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\DocumentWithCascadeDelete;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\PageWithMissingTimezone;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\PostWithBadSoftDelete;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\PostWithGoodSoftDelete;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;

/**
 * Comprehensive tests for SoftDeleteableTraitAnalyzer.
 *
 * Tests detection of:
 * 1. Non-nullable deletedAt field (CRITICAL - must be nullable!)
 * 2. Mutable DateTime (should use DateTimeImmutable)
 * 3. Public setters on soft delete fields
 * 4. Missing timezone information
 * 5. CASCADE DELETE conflicts with soft delete
 */
final class SoftDeleteableTraitAnalyzerTest extends DatabaseTestCase
{
    private SoftDeleteableTraitAnalyzer $analyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        // Create schema for entities
        $this->createSchema([
            PostWithBadSoftDelete::class,
            PostWithGoodSoftDelete::class,
            ArticleWithMutableDateTime::class,
            CommentWithPublicSetter::class,
            PageWithMissingTimezone::class,
            DocumentWithCascadeDelete::class,
            Category::class,
            Product::class,
        ]);

        $this->analyzer = new SoftDeleteableTraitAnalyzer(
            $this->entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_soft_delete_issues(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - Should detect issues in bad soft delete entities
        $issuesArray = $issues->toArray();
        $softDeleteIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'SoftDelete') ||
                          str_contains($issue->getTitle(), 'deletedAt') ||
                          str_contains($issue->getTitle(), 'removedAt'),
        );

        self::assertNotEmpty($softDeleteIssues, 'Should detect soft delete issues');

        // Check that issues have proper structure
        $firstIssue = reset($softDeleteIssues);
        assert($firstIssue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotNull($firstIssue->getTitle());
        self::assertNotNull($firstIssue->getDescription());
        self::assertNotNull($firstIssue->getSeverity());
        self::assertNotNull($firstIssue->getCategory());
    }

    #[Test]
    public function it_detects_not_nullable_deleted_at_field(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $notNullableIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'CRITICAL') &&
                          str_contains($issue->getTitle(), 'Not Nullable') &&
                          str_contains($issue->getTitle(), 'PostWithBadSoftDelete'),
        );

        self::assertNotEmpty($notNullableIssues, 'Should detect non-nullable deletedAt field');

        $issue = reset($notNullableIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertStringContainsString('CRITICAL', $issue->getDescription());
        self::assertStringContainsString('nullable', $issue->getDescription());
        self::assertSame('critical', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_mutable_datetime(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $mutableIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Mutable DateTime') &&
                          (str_contains($issue->getTitle(), 'ArticleWithMutableDateTime') ||
                           str_contains($issue->getTitle(), 'PostWithBadSoftDelete')),
        );

        self::assertNotEmpty($mutableIssues, 'Should detect mutable DateTime in soft delete field');

        $issue = reset($mutableIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertStringContainsString('DateTimeImmutable', $issue->getDescription());
        self::assertSame('warning', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_public_setters(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $setterIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Public Setter') &&
                          (str_contains($issue->getTitle(), 'CommentWithPublicSetter') ||
                           str_contains($issue->getTitle(), 'PostWithBadSoftDelete')),
        );

        self::assertNotEmpty($setterIssues, 'Should detect public setters on soft delete fields');

        $issue = reset($setterIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertStringContainsString('public setter', $issue->getDescription());
        self::assertSame('warning', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_missing_timezone(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $timezoneIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Missing Timezone') &&
                          (str_contains($issue->getTitle(), 'PageWithMissingTimezone') ||
                           str_contains($issue->getTitle(), 'ArticleWithMutableDateTime') ||
                           str_contains($issue->getTitle(), 'PostWithBadSoftDelete')),
        );

        self::assertNotEmpty($timezoneIssues, 'Should detect missing timezone on soft delete field');

        $issue = reset($timezoneIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertStringContainsString('timezone', $issue->getDescription());
        self::assertSame('info', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_cascade_delete_conflicts(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $cascadeIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'CASCADE DELETE Conflict') &&
                          (str_contains($issue->getTitle(), 'DocumentWithCascadeDelete') ||
                           str_contains($issue->getTitle(), 'PostWithBadSoftDelete')),
        );

        self::assertNotEmpty($cascadeIssues, 'Should detect CASCADE DELETE conflicts with soft delete');

        $issue = reset($cascadeIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertStringContainsString('CASCADE DELETE', $issue->getDescription());
        self::assertStringContainsString('soft delete', $issue->getDescription());
        self::assertSame('critical', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_does_not_flag_correct_soft_delete_configuration(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - Filter for good entity only
        $issuesArray = $issues->toArray();
        $goodEntityIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'PostWithGoodSoftDelete'),
        );

        self::assertCount(0, $goodEntityIssues, 'Should NOT flag correct soft delete configuration');
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
        $softDeleteIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'SoftDelete') ||
                          str_contains($issue->getTitle(), 'deletedAt'),
        );

        if (!empty($softDeleteIssues)) {
            $issue = reset($softDeleteIssues);
            assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
            self::assertNotFalse($issue);
            $suggestion = $issue->getSuggestion();
            self::assertNotNull($suggestion, 'Should provide suggestion');
            self::assertNotNull($suggestion->getMetadata()->title);
        } else {
            self::markTestSkipped('No soft delete issues found');
        }
    }

    #[Test]
    public function it_handles_entity_without_soft_delete_fields(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - Product entity should not have any soft delete issues
        $issuesArray = $issues->toArray();
        $productIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Product') &&
                          (str_contains($issue->getTitle(), 'SoftDelete') ||
                           str_contains($issue->getTitle(), 'deletedAt')),
        );

        self::assertCount(0, $productIssues, 'Should NOT flag entity without soft delete fields');
    }

    #[Test]
    public function it_detects_multiple_issues_on_same_entity(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - PostWithBadSoftDelete should have multiple issues
        $issuesArray = $issues->toArray();
        $badPostIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'PostWithBadSoftDelete'),
        );

        self::assertGreaterThanOrEqual(3, count($badPostIssues), 'Should detect multiple issues on PostWithBadSoftDelete');
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
    public function it_implements_analyzer_interface(): void
    {
        // Assert
        self::assertInstanceOf(\AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface::class, $this->analyzer);
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
    public function it_provides_correct_severity_for_critical_issues(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - Critical issues should have 'critical' severity
        $issuesArray = $issues->toArray();
        $criticalIssues = array_filter(
            $issuesArray,
            fn ($issue) => 'critical' === $issue->getSeverity()->value &&
                          (str_contains($issue->getTitle(), 'Not Nullable') ||
                           str_contains($issue->getTitle(), 'CASCADE DELETE')),
        );

        self::assertNotEmpty($criticalIssues, 'Should have critical issues');

        foreach ($criticalIssues as $issue) {
            self::assertSame('critical', $issue->getSeverity()->value);
            self::assertStringContainsString('CRITICAL', $issue->getDescription());
        }
    }

    #[Test]
    public function it_provides_correct_category_for_issues(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $softDeleteIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'SoftDelete') ||
                          str_contains($issue->getTitle(), 'deletedAt'),
        );

        self::assertNotEmpty($softDeleteIssues, 'Should have soft delete issues');

        foreach ($softDeleteIssues as $issue) {
            $category = $issue->getCategory();
            self::assertContains($category, ['configuration', 'integrity'], 'Category should be valid');
        }
    }
}
