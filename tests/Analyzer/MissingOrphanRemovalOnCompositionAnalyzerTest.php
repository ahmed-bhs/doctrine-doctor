<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\MissingOrphanRemovalOnCompositionAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\MissingOrphanRemovalTest\CartWithPartialSignals;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\MissingOrphanRemovalTest\InvoiceWithCorrectConfig;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\MissingOrphanRemovalTest\OrderWithMissingOrphanRemoval;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\MissingOrphanRemovalTest\UserWithIndependentRelation;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for MissingOrphanRemovalOnCompositionAnalyzer.
 *
 * This analyzer detects composition relationships without orphanRemoval=true,
 * which leaves orphaned records in the database when children are removed
 * from the collection.
 *
 * The analyzer uses 3 signals to identify composition:
 * 1. cascade="remove" present
 * 2. Child entity name suggests composition (Item, Line, Entry, Detail, etc.)
 * 3. Foreign key NOT NULL (child must have parent)
 *
 * If at least 2 signals are present, it's considered a composition.
 */
final class MissingOrphanRemovalOnCompositionAnalyzerTest extends TestCase
{
    private MissingOrphanRemovalOnCompositionAnalyzer $analyzer;

    protected function setUp(): void
    {
        // Create EntityManager with ONLY missing orphan removal test entities
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/MissingOrphanRemovalTest',
        ]);

        $this->analyzer = new MissingOrphanRemovalOnCompositionAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_missing_orphan_removal_with_all_three_signals(): void
    {
        // Arrange: OrderWithMissingOrphanRemoval has cascade remove + Item name + NOT NULL FK
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect missing orphanRemoval
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithMissingOrphanRemoval');
        });

        self::assertCount(1, $orderIssues, 'Should detect missing orphanRemoval with 3 signals');

        $issue = reset($orderIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals('critical', $issue->getSeverity()->value, 'Should be critical (NOT NULL FK)');
        self::assertStringContainsString('orphanRemoval', $issue->getTitle());
    }

    #[Test]
    public function it_detects_missing_orphan_removal_with_two_signals(): void
    {
        // Arrange: CartWithPartialSignals has cascade remove + Item name (but nullable FK)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect missing orphanRemoval with 2 signals
        $issuesArray = $issues->toArray();
        $cartIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'CartWithPartialSignals');
        });

        self::assertCount(1, $cartIssues, 'Should detect missing orphanRemoval with 2 signals');

        $issue = reset($cartIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals('warning', $issue->getSeverity()->value, 'Should be warning (nullable FK)');
    }

    #[Test]
    public function it_does_not_flag_correct_configuration(): void
    {
        // Arrange: InvoiceWithCorrectConfig has orphanRemoval=true
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect issues
        $issuesArray = $issues->toArray();
        $invoiceIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'InvoiceWithCorrectConfig');
        });

        self::assertCount(0, $invoiceIssues, 'Correct configuration should not trigger issues');
    }

    #[Test]
    public function it_does_not_flag_independent_relationships(): void
    {
        // Arrange: UserWithIndependentRelation has no composition signals
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect issues
        $issuesArray = $issues->toArray();
        $userIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'UserWithIndependentRelation');
        });

        self::assertCount(0, $userIssues, 'Independent relationships should not trigger issues');
    }

    #[Test]
    public function it_includes_entity_and_field_in_issue_data(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithMissingOrphanRemoval');
        });

        self::assertGreaterThan(0, count($orderIssues));

        $issue = reset($orderIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('entity', $data);
        self::assertArrayHasKey('field', $data);
        self::assertArrayHasKey('target_entity', $data);
        self::assertEquals('items', $data['field']);
        self::assertStringContainsString('OrderItemMissing', $data['target_entity']);
    }

    #[Test]
    public function it_provides_helpful_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithMissingOrphanRemoval');
        });

        self::assertGreaterThan(0, count($orderIssues));

        $issue = reset($orderIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
    }

    #[Test]
    public function it_detects_all_problematic_entities(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect 2 issues (Order + Cart)
        $issuesArray = $issues->toArray();

        self::assertGreaterThanOrEqual(2, count($issuesArray), 'Should detect at least 2 issues');

        // Verify both Order and Cart are detected
        $entityNames = array_map(fn ($issue) => $issue->getData()['entity'] ?? '', $issuesArray);
        $hasOrder = false;
        $hasCart = false;

        foreach ($entityNames as $entityName) {
            if (str_contains($entityName, 'OrderWithMissingOrphanRemoval')) {
                $hasOrder = true;
            }
            if (str_contains($entityName, 'CartWithPartialSignals')) {
                $hasCart = true;
            }
        }

        self::assertTrue($hasOrder, 'Should detect Order entity');
        self::assertTrue($hasCart, 'Should detect Cart entity');
    }

    #[Test]
    public function it_sets_correct_severity_for_not_null_fk(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Order has NOT NULL FK → critical
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithMissingOrphanRemoval');
        });

        self::assertGreaterThan(0, count($orderIssues));

        $issue = reset($orderIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals('critical', $issue->getSeverity()->value);

        $data = $issue->getData();
        self::assertFalse($data['nullable_fk'] ?? true, 'FK should be NOT NULL');
    }

    #[Test]
    public function it_sets_correct_severity_for_nullable_fk(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Cart has nullable FK → high
        $issuesArray = $issues->toArray();
        $cartIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'CartWithPartialSignals');
        });

        self::assertGreaterThan(0, count($cartIssues));

        $issue = reset($cartIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals('warning', $issue->getSeverity()->value);

        $data = $issue->getData();
        self::assertTrue($data['nullable_fk'] ?? false, 'FK should be nullable');
    }

    #[Test]
    public function it_includes_cascade_information_in_issue_data(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithMissingOrphanRemoval');
        });

        self::assertGreaterThan(0, count($orderIssues));

        $issue = reset($orderIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('cascade', $data);
        self::assertArrayHasKey('has_cascade_remove', $data);
        self::assertTrue($data['has_cascade_remove'], 'Should have cascade remove');
        self::assertContains('remove', $data['cascade']);
    }

    #[Test]
    public function it_handles_empty_query_collection(): void
    {
        // Arrange: Empty collection (analyzer doesn't use queries, but tests interface)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should still analyze entities (not query-based)
        self::assertIsObject($issues);
        self::assertIsArray($issues->toArray());
    }

    #[Test]
    public function it_has_analyzer_name(): void
    {
        // Assert
        $name = $this->analyzer->getName();

        self::assertNotEmpty($name);
        self::assertStringContainsString('Orphan', $name);
        self::assertStringContainsString('Removal', $name);
    }

    #[Test]
    public function it_has_analyzer_description(): void
    {
        // Assert
        $description = $this->analyzer->getDescription();

        self::assertNotEmpty($description);
        self::assertStringContainsString('orphan', strtolower($description));
        self::assertStringContainsString('composition', strtolower($description));
    }

    #[Test]
    public function it_detects_composition_by_cascade_and_name(): void
    {
        // Arrange: Entities with cascade remove + "Item" name pattern
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Both Order and Cart have cascade + name pattern
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            $data = $issue->getData();
            $entityName = $data['entity'] ?? '';

            if (str_contains($entityName, 'OrderWithMissingOrphanRemoval') ||
                str_contains($entityName, 'CartWithPartialSignals')) {
                self::assertTrue($data['has_cascade_remove'], 'Should have cascade remove');
                self::assertStringContainsString('Item', $data['target_entity'], 'Should have Item pattern');
            }
        }
    }

    #[Test]
    public function it_includes_backtrace_information(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithMissingOrphanRemoval');
        });

        self::assertGreaterThan(0, count($orderIssues));

        $issue = reset($orderIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('backtrace', $data);
        $backtrace = $data['backtrace'];

        if (null !== $backtrace) {
            self::assertIsArray($backtrace);
            self::assertNotEmpty($backtrace);
            self::assertArrayHasKey('file', $backtrace[0]);
            self::assertArrayHasKey('class', $backtrace[0]);
        }
    }

    #[Test]
    public function it_correctly_identifies_composition_patterns(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: All detected issues should have composition patterns
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            $data = $issue->getData();
            $targetEntity = $data['target_entity'] ?? '';

            // Target entity should contain composition pattern
            $hasPattern = str_contains($targetEntity, 'Item') ||
                          str_contains($targetEntity, 'Line') ||
                          str_contains($targetEntity, 'Entry') ||
                          str_contains($targetEntity, 'Detail');

            // OR should have cascade remove
            $hasCascade = $data['has_cascade_remove'] ?? false;

            // At least one signal should be present
            self::assertTrue($hasPattern || $hasCascade, 'Should have at least one composition signal'); // @phpstan-ignore-line booleanOr.rightNotBoolean
        }
    }

    #[Test]
    public function it_provides_different_messages_based_on_fk_nullability(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();

        $criticalIssue = null;
        $warningIssue = null;

        foreach ($issuesArray as $issue) {
            if ('critical' === $issue->getSeverity()->value) {
                $criticalIssue = $issue;
            } elseif ('warning' === $issue->getSeverity()->value) {
                $warningIssue = $issue;
            }
        }

        self::assertNotNull($criticalIssue, 'Should have at least one critical issue');
        self::assertNotNull($warningIssue, 'Should have at least one warning issue');

        // Both should have suggestions
        self::assertNotNull($criticalIssue->getSuggestion());
        self::assertNotNull($warningIssue->getSuggestion());
    }

    #[Test]
    public function it_verifies_target_entity_references(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Target entities should exist and be valid
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            $data = $issue->getData();
            $targetEntity = $data['target_entity'] ?? '';

            self::assertNotEmpty($targetEntity);
            self::assertNotEquals('Unknown', $targetEntity);

            // Should be one of our test entities
            $isValidTarget = str_contains($targetEntity, 'OrderItemMissing') ||
                             str_contains($targetEntity, 'CartItemPartial');

            self::assertTrue($isValidTarget, "Target entity {$targetEntity} should be valid");
        }
    }

    #[Test]
    public function it_maintains_critical_severity_for_app_code_entities(): void
    {
        // Verify app code entities get correct severity (not downgraded)
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        // Find issues with NOT NULL FK (should be CRITICAL for app code)
        $criticalIssues = array_filter(
            $issuesArray,
            fn ($issue) => false === $issue->getData()['nullable_fk'],
        );

        foreach ($criticalIssues as $issue) {
            // App code with NOT NULL FK should be CRITICAL
            self::assertEquals('critical', $issue->getSeverity()->value);
            // Should NOT have vendor dependency marker
            self::assertStringNotContainsString('vendor dependency', $issue->getTitle());
        }
    }

    #[Test]
    public function it_does_not_add_vendor_warnings_to_app_entities(): void
    {
        // Verify app entities don't get vendor-specific messages
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            // App entities should NOT have vendor warning
            self::assertStringNotContainsString(
                'vendor dependency',
                $issue->getDescription(),
                'App entities should not have vendor warnings',
            );
            self::assertStringNotContainsString(
                'intentional design choice',
                $issue->getDescription(),
                'App entities should not reference vendor design choices',
            );
        }
    }
}
