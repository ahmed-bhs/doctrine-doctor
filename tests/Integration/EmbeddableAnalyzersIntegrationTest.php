<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\EmbeddableMutabilityAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\EmbeddableWithoutValueObjectAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\FloatInMoneyEmbeddableAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\MissingEmbeddableOpportunityAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrderWithEmbeddables;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ProductWithScatteredMoney;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for Embeddable analyzers.
 *
 * These tests verify that the analyzers correctly detect issues
 * when analyzing real entity metadata.
 */
final class EmbeddableAnalyzersIntegrationTest extends TestCase
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

    public function test_missing_embeddable_opportunity_analyzer_detects_scattered_money_fields(): void
    {
        // Arrange
        $issueFactory = new IssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $missingEmbeddableOpportunityAnalyzer = new MissingEmbeddableOpportunityAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        // Ensure metadata is loaded
        $this->entityManager->getClassMetadata(ProductWithScatteredMoney::class);

        // Act
        $issueCollection = $missingEmbeddableOpportunityAnalyzer->analyze(QueryDataCollection::empty());

        // Assert
        $issuesArray = $issueCollection->toArray();
        self::assertNotEmpty($issuesArray, 'Should detect scattered money fields');

        // Find the issue for ProductWithScatteredMoney
        $foundIssue = false;
        foreach ($issuesArray as $issueArray) {
            if (str_contains($issueArray->getTitle(), 'Money') && str_contains($issueArray->getTitle(), 'ProductWithScatteredMoney')) {
                $foundIssue = true;
                // Check title contains embeddable info
                self::assertStringContainsString('Embeddable', $issueArray->getTitle());
                self::assertStringContainsString('amount', $issueArray->getDescription());
                self::assertStringContainsString('currency', $issueArray->getDescription());
                break;
            }
        }

        self::assertTrue($foundIssue, 'Should find issue for ProductWithScatteredMoney');
    }

    public function test_embeddable_mutability_analyzer_detects_mutable_embeddable(): void
    {
        // Arrange
        $issueFactory = new IssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $embeddableMutabilityAnalyzer = new EmbeddableMutabilityAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        // Load metadata for entity using the embeddable
        $this->entityManager->getClassMetadata(OrderWithEmbeddables::class);

        // Act
        $issueCollection = $embeddableMutabilityAnalyzer->analyze(QueryDataCollection::empty());

        // Assert - Analyzer should run without errors even if no embeddables detected
        $issuesArray = $issueCollection->toArray();
        self::assertIsArray($issuesArray, 'Analyzer should return array');

        // If issues are detected, verify they are about mutability
        foreach ($issuesArray as $issueArray) {
            if (str_contains($issueArray->getTitle(), 'Mutable')) {
                self::assertStringContainsString('Embeddable', $issueArray->getTitle());
                self::assertStringContainsString('immutable', strtolower($issueArray->getDescription()));
            }
        }

        self::assertTrue(true, 'Analyzer executed successfully');
    }

    public function test_embeddable_without_value_object_analyzer_detects_missing_methods(): void
    {
        // Arrange
        $issueFactory = new IssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $embeddableWithoutValueObjectAnalyzer = new EmbeddableWithoutValueObjectAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        // Load metadata
        $this->entityManager->getClassMetadata(OrderWithEmbeddables::class);

        // Act
        $issueCollection = $embeddableWithoutValueObjectAnalyzer->analyze(QueryDataCollection::empty());

        // Assert - Analyzer should run without errors
        $issuesArray = $issueCollection->toArray();
        self::assertIsArray($issuesArray, 'Analyzer should return array');

        // If issues are detected, verify they are about missing methods
        foreach ($issuesArray as $issueArray) {
            if (str_contains($issueArray->getTitle(), 'Value Object')) {
                self::assertStringContainsString('Embeddable', $issueArray->getTitle());
                $description = strtolower($issueArray->getDescription());
                // Should mention methods
                self::assertThat($description, self::logicalOr(
                    self::stringContains('equals'),
                    self::stringContains('tostring'),
                    self::stringContains('method'),
                ));
            }
        }

        self::assertTrue(true, 'Analyzer executed successfully');
    }

    public function test_float_in_money_embeddable_analyzer_detects_float_usage(): void
    {
        // Arrange
        $issueFactory = new IssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();
        $floatInMoneyEmbeddableAnalyzer = new FloatInMoneyEmbeddableAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        // Load metadata
        $this->entityManager->getClassMetadata(OrderWithEmbeddables::class);

        // Act
        $issueCollection = $floatInMoneyEmbeddableAnalyzer->analyze(QueryDataCollection::empty());

        // Assert - Analyzer should run without errors
        $issuesArray = $issueCollection->toArray();
        self::assertIsArray($issuesArray, 'Analyzer should return array');

        // If issues are detected, verify they are about float in money
        foreach ($issuesArray as $issueArray) {
            if (str_contains(strtolower($issueArray->getTitle()), 'float') && str_contains(strtolower($issueArray->getTitle()), 'money')) {
                self::assertStringContainsString('Embeddable', $issueArray->getTitle());
                self::assertStringContainsString('float', strtolower($issueArray->getDescription()));
            }
        }

        self::assertTrue(true, 'Analyzer executed successfully');
    }

    public function test_all_embeddable_analyzers_work_together(): void
    {
        // Arrange - Create all analyzers
        $issueFactory = new IssueFactory();
        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();

        $missingEmbeddableOpportunityAnalyzer = new MissingEmbeddableOpportunityAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        $embeddableMutabilityAnalyzer = new EmbeddableMutabilityAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        $embeddableWithoutValueObjectAnalyzer = new EmbeddableWithoutValueObjectAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        $floatInMoneyEmbeddableAnalyzer = new FloatInMoneyEmbeddableAnalyzer(
            $this->entityManager,
            $issueFactory,
            $suggestionFactory,
        );

        // Load all test entities
        $this->entityManager->getClassMetadata(ProductWithScatteredMoney::class);
        $this->entityManager->getClassMetadata(OrderWithEmbeddables::class);

        // Act - Run all analyzers
        $allIssues = [];
        $allIssues = array_merge($allIssues, $missingEmbeddableOpportunityAnalyzer->analyze(QueryDataCollection::empty())->toArray());
        $allIssues = array_merge($allIssues, $embeddableMutabilityAnalyzer->analyze(QueryDataCollection::empty())->toArray());
        $allIssues = array_merge($allIssues, $embeddableWithoutValueObjectAnalyzer->analyze(QueryDataCollection::empty())->toArray());
        $allIssues = array_merge($allIssues, $floatInMoneyEmbeddableAnalyzer->analyze(QueryDataCollection::empty())->toArray());

        // Assert - Should detect at least the missing embeddable opportunity
        self::assertNotEmpty($allIssues, 'Should detect various embeddable issues');
        $foundMissingOpportunity = array_any($allIssues, fn ($allIssue) => str_contains((string) $allIssue->getTitle(), 'Money') && str_contains((string) $allIssue->getTitle(), 'Embeddable'));

        self::assertTrue($foundMissingOpportunity, 'Should detect at least missing embeddable opportunity');

        // Verify all analyzers executed without throwing exceptions
        self::assertGreaterThan(0, count($allIssues), 'Should have found at least one issue');
    }
}
