<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\BlameableTraitAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\SoftDeleteableTraitAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\TimestampableTraitAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ArticleWithBadBlameable;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\PostWithBadSoftDelete;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ProductWithBadTimestamps;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for Doctrine Extensions analyzers.
 *
 * Tests the analyzers with real entity metadata to ensure
 * they correctly detect bad practices in Timestampable, Blameable,
 * and SoftDeleteable implementations.
 */
final class DoctrineExtensionsAnalyzersIntegrationTest extends TestCase
{
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        // Create a real EntityManager with test entities
        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../Fixtures'],
            isDevMode: true,
        );

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $configuration);

        $this->entityManager = new EntityManager($connection, $configuration);
    }

    // ========================================
    // TimestampableTraitAnalyzer Tests
    // ========================================

    public function test_timestampable_analyzer_detects_mutable_datetime(): void
    {
        // Arrange
        $issueFactory = new IssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $timestampableTraitAnalyzer = new TimestampableTraitAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        // Load entity with bad timestamps
        $this->entityManager->getClassMetadata(ProductWithBadTimestamps::class);

        // Act
        $issueCollection = $timestampableTraitAnalyzer->analyze(QueryDataCollection::empty());

        // Assert
        $issuesArray = $issueCollection->toArray();
        self::assertNotEmpty($issuesArray, 'Should detect timestamp issues');

        // Find mutable DateTime issue
        $foundMutableIssue = false;
        foreach ($issuesArray as $issueArray) {
            if (str_contains(strtolower($issueArray->getTitle()), 'mutable')
                && str_contains(strtolower($issueArray->getTitle()), 'datetime')) {
                $foundMutableIssue = true;
                self::assertStringContainsString('DateTimeImmutable', $issueArray->getDescription());
                break;
            }
        }

        self::assertTrue($foundMutableIssue, 'Should detect mutable DateTime usage');
    }

    public function test_timestampable_analyzer_detects_missing_timezone(): void
    {
        // Arrange
        $issueFactory = new IssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $timestampableTraitAnalyzer = new TimestampableTraitAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        $this->entityManager->getClassMetadata(ProductWithBadTimestamps::class);

        // Act
        $issueCollection = $timestampableTraitAnalyzer->analyze(QueryDataCollection::empty());

        // Assert
        $issuesArray = $issueCollection->toArray();

        // Find missing timezone issue
        $foundTimezoneIssue = false;
        foreach ($issuesArray as $issueArray) {
            if (str_contains(strtolower($issueArray->getTitle()), 'timezone')
                || str_contains(strtolower($issueArray->getDescription()), 'timezone')) {
                $foundTimezoneIssue = true;
                self::assertStringContainsString('datetimetz', strtolower($issueArray->getDescription()));
                break;
            }
        }

        self::assertTrue($foundTimezoneIssue, 'Should detect missing timezone');
    }

    public function test_timestampable_analyzer_detects_public_setter(): void
    {
        // Arrange
        $issueFactory = new IssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $timestampableTraitAnalyzer = new TimestampableTraitAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        $this->entityManager->getClassMetadata(ProductWithBadTimestamps::class);

        // Act
        $issueCollection = $timestampableTraitAnalyzer->analyze(QueryDataCollection::empty());

        // Assert
        $issuesArray = $issueCollection->toArray();
        $foundSetterIssue = array_any($issuesArray, fn ($issueArray) => str_contains(strtolower((string) $issueArray->getTitle()), 'setter')
            || str_contains(strtolower((string) $issueArray->getDescription()), 'setter'));

        self::assertTrue($foundSetterIssue, 'Should detect public setters on timestamp fields');
    }

    // ========================================
    // BlameableTraitAnalyzer Tests
    // ========================================

    public function test_blameable_analyzer_detects_nullable_created_by(): void
    {
        // Arrange
        $issueFactory = new IssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $blameableTraitAnalyzer = new BlameableTraitAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        $this->entityManager->getClassMetadata(ArticleWithBadBlameable::class);

        // Act
        $issueCollection = $blameableTraitAnalyzer->analyze(QueryDataCollection::empty());

        // Assert
        $issuesArray = $issueCollection->toArray();
        self::assertNotEmpty($issuesArray, 'Should detect blameable issues');

        // Find nullable createdBy issue
        $foundNullableIssue = false;
        foreach ($issuesArray as $issueArray) {
            if (str_contains(strtolower($issueArray->getTitle()), 'nullable')
                && (str_contains(strtolower($issueArray->getTitle()), 'creator')
                    || str_contains(strtolower($issueArray->getTitle()), 'createdby'))) {
                $foundNullableIssue = true;
                self::assertStringContainsString('NOT NULL', $issueArray->getDescription());
                break;
            }
        }

        self::assertTrue($foundNullableIssue, 'Should detect nullable createdBy');
    }

    public function test_blameable_analyzer_detects_public_setter(): void
    {
        // Arrange
        $issueFactory = new IssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $blameableTraitAnalyzer = new BlameableTraitAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        $this->entityManager->getClassMetadata(ArticleWithBadBlameable::class);

        // Act
        $issueCollection = $blameableTraitAnalyzer->analyze(QueryDataCollection::empty());

        // Assert
        $issuesArray = $issueCollection->toArray();

        // Find public setter issue
        $foundSetterIssue = false;
        foreach ($issuesArray as $issueArray) {
            if (str_contains(strtolower($issueArray->getTitle()), 'setter')
                && str_contains(strtolower($issueArray->getTitle()), 'blameable')) {
                $foundSetterIssue = true;
                self::assertStringContainsString('audit', strtolower($issueArray->getDescription()));
                break;
            }
        }

        self::assertTrue($foundSetterIssue, 'Should detect public setters on blameable fields');
    }

    // ========================================
    // SoftDeleteableTraitAnalyzer Tests
    // ========================================

    public function test_soft_delete_analyzer_detects_not_nullable_critical_issue(): void
    {
        // Arrange
        $issueFactory = new IssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $softDeleteableTraitAnalyzer = new SoftDeleteableTraitAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        $this->entityManager->getClassMetadata(PostWithBadSoftDelete::class);

        // Act
        $issueCollection = $softDeleteableTraitAnalyzer->analyze(QueryDataCollection::empty());

        // Assert
        $issuesArray = $issueCollection->toArray();
        self::assertNotEmpty($issuesArray, 'Should detect soft delete issues');

        // Find NOT nullable critical issue
        $foundCriticalIssue = false;
        foreach ($issuesArray as $issueArray) {
            if (str_contains(strtolower($issueArray->getTitle()), 'nullable')
                && str_contains(strtolower($issueArray->getTitle()), 'deletedat')) {
                $foundCriticalIssue = true;
                self::assertSame('critical', $issueArray->getSeverity()->value);
                self::assertStringContainsString('nullable', strtolower($issueArray->getDescription()));
                self::assertStringContainsString('null = not deleted', strtolower($issueArray->getDescription()));
                break;
            }
        }

        self::assertTrue($foundCriticalIssue, 'Should detect CRITICAL not nullable deletedAt issue');
    }

    public function test_soft_delete_analyzer_detects_cascade_delete_conflict(): void
    {
        // Arrange
        $issueFactory = new IssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $softDeleteableTraitAnalyzer = new SoftDeleteableTraitAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        $this->entityManager->getClassMetadata(PostWithBadSoftDelete::class);

        // Act
        $issueCollection = $softDeleteableTraitAnalyzer->analyze(QueryDataCollection::empty());

        // Assert
        $issuesArray = $issueCollection->toArray();

        // Find CASCADE DELETE conflict
        $foundCascadeIssue = false;
        foreach ($issuesArray as $issueArray) {
            if (str_contains(strtolower($issueArray->getTitle()), 'cascade')
                && str_contains(strtolower($issueArray->getDescription()), 'cascade delete')) {
                $foundCascadeIssue = true;
                self::assertSame('critical', $issueArray->getSeverity()->value);
                self::assertStringContainsString('data loss', strtolower($issueArray->getDescription()));
                break;
            }
        }

        self::assertTrue($foundCascadeIssue, 'Should detect CASCADE DELETE conflict with soft delete');
    }

    public function test_soft_delete_analyzer_detects_mutable_datetime(): void
    {
        // Arrange
        $issueFactory = new IssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $softDeleteableTraitAnalyzer = new SoftDeleteableTraitAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        $this->entityManager->getClassMetadata(PostWithBadSoftDelete::class);

        // Act
        $issueCollection = $softDeleteableTraitAnalyzer->analyze(QueryDataCollection::empty());

        // Assert
        $issuesArray = $issueCollection->toArray();

        // Find mutable DateTime issue
        $foundMutableIssue = false;
        foreach ($issuesArray as $issueArray) {
            if (str_contains(strtolower($issueArray->getTitle()), 'mutable')
                && str_contains(strtolower($issueArray->getTitle()), 'datetime')) {
                $foundMutableIssue = true;
                self::assertStringContainsString('DateTimeImmutable', $issueArray->getDescription());
                break;
            }
        }

        self::assertTrue($foundMutableIssue, 'Should detect mutable DateTime in soft delete field');
    }

    public function test_soft_delete_analyzer_detects_public_setter(): void
    {
        // Arrange
        $issueFactory = new IssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $softDeleteableTraitAnalyzer = new SoftDeleteableTraitAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        $this->entityManager->getClassMetadata(PostWithBadSoftDelete::class);

        // Act
        $issueCollection = $softDeleteableTraitAnalyzer->analyze(QueryDataCollection::empty());

        // Assert
        $issuesArray = $issueCollection->toArray();
        $foundSetterIssue = array_any($issuesArray, fn ($issueArray) => str_contains(strtolower((string) $issueArray->getTitle()), 'setter')
            && str_contains(strtolower((string) $issueArray->getDescription()), 'soft delete'));

        self::assertTrue($foundSetterIssue, 'Should detect public setters on soft delete fields');
    }

    // ========================================
    // Combined Test
    // ========================================

    public function test_all_extension_analyzers_work_together(): void
    {
        // Arrange - Create all analyzers
        $issueFactory = new IssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();

        $timestampableTraitAnalyzer = new TimestampableTraitAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        $blameableTraitAnalyzer = new BlameableTraitAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        $softDeleteableTraitAnalyzer = new SoftDeleteableTraitAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        // Load all test entities
        $this->entityManager->getClassMetadata(ProductWithBadTimestamps::class);
        $this->entityManager->getClassMetadata(ArticleWithBadBlameable::class);
        $this->entityManager->getClassMetadata(PostWithBadSoftDelete::class);

        // Act - Run all analyzers
        $allIssues = [];
        $allIssues = array_merge($allIssues, $timestampableTraitAnalyzer->analyze(QueryDataCollection::empty())->toArray());
        $allIssues = array_merge($allIssues, $blameableTraitAnalyzer->analyze(QueryDataCollection::empty())->toArray());
        $allIssues = array_merge($allIssues, $softDeleteableTraitAnalyzer->analyze(QueryDataCollection::empty())->toArray());

        // Assert
        self::assertNotEmpty($allIssues, 'Should detect multiple extension issues');

        // Count issues by type
        $timestampableIssues = 0;
        $blameableIssues = 0;
        $softDeleteIssues = 0;
        $criticalIssues = 0;

        foreach ($allIssues as $allIssue) {
            $title = strtolower($allIssue->getTitle());

            if (str_contains($title, 'timestamp') || str_contains($title, 'datetime')) {
                $timestampableIssues++;
            }

            if (str_contains($title, 'blameable') || str_contains($title, 'creator')) {
                $blameableIssues++;
            }

            if (str_contains($title, 'soft') || str_contains($title, 'deleted')) {
                $softDeleteIssues++;
            }

            if ('critical' === $allIssue->getSeverity()->value) {
                $criticalIssues++;
            }
        }

        self::assertGreaterThan(0, $timestampableIssues, 'Should detect Timestampable issues');
        self::assertGreaterThan(0, $blameableIssues, 'Should detect Blameable issues');
        self::assertGreaterThan(0, $softDeleteIssues, 'Should detect SoftDeleteable issues');
        self::assertGreaterThan(0, $criticalIssues, 'Should detect CRITICAL issues (soft delete NOT nullable, CASCADE conflict)');

        // Verify we found all major issues
        self::assertGreaterThanOrEqual(8, count($allIssues), 'Should find at least 8 different issues across all analyzers');
    }
}
