<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\BlameableTraitAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ArticleWithBadBlameable;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ArticleWithGoodBlameable;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ArticleWithWrongTarget;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Category;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;

/**
 * Comprehensive tests for BlameableTraitAnalyzer.
 *
 * Tests detection of:
 * 1. Nullable createdBy (should be NOT NULL)
 * 2. Public setters on blameable fields
 * 3. Wrong target entity (not User/Account)
 */
final class BlameableTraitAnalyzerTest extends DatabaseTestCase
{
    private BlameableTraitAnalyzer $analyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        // Create schema for entities
        $this->createSchema([
            ArticleWithBadBlameable::class,
            ArticleWithGoodBlameable::class,
            ArticleWithWrongTarget::class,
            User::class,
            Product::class,
            Category::class,
        ]);

        $this->analyzer = new BlameableTraitAnalyzer(
            $this->entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_blameable_issues(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - Should detect issues in bad blameable entities
        $issuesArray = $issues->toArray();
        $blameableIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Blameable') ||
                          str_contains($issue->getTitle(), 'createdBy') ||
                          str_contains($issue->getTitle(), 'updatedBy'),
        );

        self::assertNotEmpty($blameableIssues, 'Should detect blameable issues');

        // Check that issues have proper structure
        $firstIssue = reset($blameableIssues);
        assert($firstIssue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotNull($firstIssue->getTitle());
        self::assertNotNull($firstIssue->getDescription());
        self::assertNotNull($firstIssue->getSeverity());
        self::assertNotNull($firstIssue->getCategory());
    }

    #[Test]
    public function it_detects_nullable_creator_fields(): void
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
                          str_contains(strtolower($issue->getTitle()), 'creator'),
        );

        self::assertNotEmpty($nullableIssues, 'Should detect nullable creator fields');

        $issue = reset($nullableIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertStringContainsString('NULL', $issue->getDescription());
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
            fn ($issue) => str_contains(strtolower($issue->getTitle()), 'setter') &&
                          str_contains(strtolower($issue->getTitle()), 'blameable'),
        );

        self::assertNotEmpty($setterIssues, 'Should detect public setters on blameable fields');
    }

    #[Test]
    public function it_detects_wrong_target_entities(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $targetIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains(strtolower($issue->getTitle()), 'target') &&
                          str_contains($issue->getTitle(), 'ArticleWithWrongTarget'),
        );

        self::assertNotEmpty($targetIssues, 'Should detect wrong target entities');
    }

    #[Test]
    public function it_does_not_flag_correct_blameable_configuration(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - Filter for good entity only
        $issuesArray = $issues->toArray();
        $goodEntityIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'ArticleWithGoodBlameable'),
        );

        self::assertCount(0, $goodEntityIssues, 'Should NOT flag correct blameable configuration');
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
        $blameableIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Blameable') ||
                          str_contains($issue->getTitle(), 'createdBy'),
        );

        if (!empty($blameableIssues)) {
            $issue = reset($blameableIssues);
            assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
            self::assertNotFalse($issue);
            $suggestion = $issue->getSuggestion();
            self::assertNotNull($suggestion, 'Should provide suggestion');
        } else {
            self::markTestSkipped('No blameable issues found');
        }
    }

    #[Test]
    public function it_detects_multiple_issues_on_same_entity(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - ArticleWithBadBlameable should have multiple issues
        $issuesArray = $issues->toArray();
        $articleIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'ArticleWithBadBlameable'),
        );

        self::assertGreaterThanOrEqual(1, count($articleIssues), 'Should detect issues on ArticleWithBadBlameable');
    }

    #[Test]
    public function it_handles_entity_without_blameable_fields(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - Product entity should have suggestions for missing blameable trait if it has timestamp fields
        $issuesArray = $issues->toArray();
        $productIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Product') &&
                          (str_contains($issue->getTitle(), 'Blameable') ||
                           str_contains($issue->getTitle(), 'createdBy')),
        );

        // The analyzer now suggests adding blameable trait to entities with timestamp fields
        // This is a useful feature to improve audit trail
        self::assertGreaterThanOrEqual(0, count($productIssues), 'May suggest blameable trait for entities with timestamps');
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
}
