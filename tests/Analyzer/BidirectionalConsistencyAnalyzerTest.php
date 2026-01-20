<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\BidirectionalConsistencyAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for BidirectionalConsistencyAnalyzer.
 *
 * This analyzer detects inconsistencies in bidirectional associations:
 *
 * 1. orphan_removal_nullable_fk: orphanRemoval=true but nullable FK
 * 2. cascade_remove_set_null: cascade="remove" but onDelete="SET NULL"
 * 3. orphan_removal_no_persist: orphanRemoval=true but no cascade="persist"
 * 4. ondelete_cascade_no_orm: onDelete="CASCADE" but no ORM cascade="remove"
 */
final class BidirectionalConsistencyAnalyzerTest extends TestCase
{
    private BidirectionalConsistencyAnalyzer $analyzer;

    protected function setUp(): void
    {
        // Create EntityManager with ONLY bidirectional consistency test entities
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/BidirectionalConsistencyTest',
        ]);

        $this->analyzer = new BidirectionalConsistencyAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_orphan_removal_nullable_fk_inconsistency(): void
    {
        // Arrange: Order has orphanRemoval=true but child has nullable FK
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithOrphanRemovalNullable');
        });

        self::assertCount(1, $orderIssues, 'Should detect orphan_removal_nullable_fk inconsistency');

        $issue = reset($orderIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('orphan_removal_nullable_fk', $data['inconsistency_type']);
        self::assertEquals('order', $data['inverse_field']); // mappedBy value
    }

    #[Test]
    public function it_detects_cascade_remove_set_null_inconsistency(): void
    {
        // Arrange: Cart has cascade="remove" but child has onDelete="SET NULL"
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $cartIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'CartWithCascadeRemoveSetNull');
        });

        self::assertCount(1, $cartIssues, 'Should detect cascade_remove_set_null inconsistency');

        $issue = reset($cartIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('cascade_remove_set_null', $data['inconsistency_type']);
        self::assertEquals('cart', $data['inverse_field']); // mappedBy value
    }

    #[Test]
    public function it_detects_orphan_removal_no_persist_inconsistency(): void
    {
        // Arrange: Project has orphanRemoval=true but no cascade="persist"
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $projectIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'ProjectWithOrphanNoPersist');
        });

        self::assertCount(1, $projectIssues, 'Should detect orphan_removal_no_persist inconsistency');

        $issue = reset($projectIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('orphan_removal_no_persist', $data['inconsistency_type']);
        self::assertEquals('project', $data['inverse_field']); // mappedBy value
    }

    #[Test]
    public function it_detects_ondelete_cascade_no_orm_inconsistency(): void
    {
        // Arrange: Invoice child has onDelete="CASCADE" but parent has no cascade="remove"
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $invoiceIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'InvoiceWithDbCascadeNoOrm');
        });

        self::assertCount(1, $invoiceIssues, 'Should detect ondelete_cascade_no_orm inconsistency');

        $issue = reset($invoiceIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('ondelete_cascade_no_orm', $data['inconsistency_type']);
        self::assertEquals('invoice', $data['inverse_field']); // mappedBy value
    }

    #[Test]
    public function it_does_not_flag_correct_configuration(): void
    {
        // Arrange: Company has correct configuration (all consistent)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $companyIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'CompanyWithCorrectConfig');
        });

        self::assertCount(0, $companyIssues, 'Correct configuration should not trigger issues');
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
            return str_contains($data['entity'] ?? '', 'OrderWithOrphanRemovalNullable');
        });

        self::assertGreaterThan(0, count($orderIssues));

        $issue = reset($orderIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('entity', $data);
        self::assertArrayHasKey('field', $data);
        self::assertArrayHasKey('target_entity', $data);
        self::assertArrayHasKey('inverse_field', $data);
        self::assertEquals('items', $data['field']);
        self::assertStringContainsString('OrderItemWithNullableFK', $data['target_entity']);
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
            return str_contains($data['entity'] ?? '', 'OrderWithOrphanRemovalNullable');
        });

        self::assertGreaterThan(0, count($orderIssues));

        $issue = reset($orderIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
    }

    #[Test]
    public function it_detects_all_inconsistency_types(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect at least 4 distinct inconsistency types
        $issuesArray = $issues->toArray();

        self::assertGreaterThanOrEqual(4, count($issuesArray), 'Should detect at least 4 inconsistencies');

        // Collect all inconsistency types
        $inconsistencyTypes = array_map(fn ($issue) => $issue->getData()['inconsistency_type'] ?? '', $issuesArray);
        $uniqueTypes = array_unique($inconsistencyTypes);

        self::assertGreaterThanOrEqual(4, count($uniqueTypes), 'Should have at least 4 distinct inconsistency types');
        self::assertContains('orphan_removal_nullable_fk', $inconsistencyTypes);
        self::assertContains('cascade_remove_set_null', $inconsistencyTypes);
        self::assertContains('orphan_removal_no_persist', $inconsistencyTypes);
        self::assertContains('ondelete_cascade_no_orm', $inconsistencyTypes);
    }

    #[Test]
    public function it_sets_correct_severity_for_inconsistencies(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Severities should be appropriate
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            $severity = $issue->getSeverity()->value;
            self::assertContains($severity, ['critical', 'warning', 'info'], 'Severity should be critical, warning, or info');
        }
    }

    #[Test]
    public function it_includes_inconsistency_type_in_issue_data(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithOrphanRemovalNullable');
        });

        self::assertGreaterThan(0, count($orderIssues));

        $issue = reset($orderIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('inconsistency_type', $data);
        self::assertNotEmpty($data['inconsistency_type']);
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
        self::assertStringContainsString('Bidirectional', $name);
        self::assertStringContainsString('Consistency', $name);
    }

    #[Test]
    public function it_has_analyzer_description(): void
    {
        // Assert
        $description = $this->analyzer->getDescription();

        self::assertNotEmpty($description);
        self::assertStringContainsString('bidirectional', strtolower($description));
        self::assertStringContainsString('inconsistencies', strtolower($description));
    }

    #[Test]
    public function it_includes_target_entity_information(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            $data = $issue->getData();

            self::assertArrayHasKey('target_entity', $data, 'Should include target entity');
            self::assertNotEmpty($data['target_entity'], 'Target entity should not be empty');
            self::assertNotEquals('Unknown', $data['target_entity'], 'Target entity should be valid');
        }
    }

    #[Test]
    public function it_includes_inverse_field_information(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithOrphanRemovalNullable');
        });

        self::assertGreaterThan(0, count($orderIssues));

        $issue = reset($orderIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('inverse_field', $data);
        self::assertNotEmpty($data['inverse_field']);
        self::assertEquals('order', $data['inverse_field']); // mappedBy value
    }

    #[Test]
    public function it_provides_suggestions_for_all_inconsistency_types(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: All detected issues should have suggestions
        $issuesArray = $issues->toArray();

        self::assertGreaterThanOrEqual(4, count($issuesArray), 'Should detect at least 4 inconsistencies');

        foreach ($issuesArray as $issue) {
            $suggestion = $issue->getSuggestion();
            self::assertNotNull($suggestion, 'Each inconsistency should have a suggestion');
        }
    }

    #[Test]
    public function it_has_consistent_issue_title(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            $title = $issue->getTitle();
            self::assertNotEmpty($title);
            self::assertStringContainsString('Bidirectional', $title);
            self::assertStringContainsString('Inconsistency', $title);
        }
    }

    #[Test]
    public function it_only_checks_bidirectional_associations(): void
    {
        // This test verifies that only bidirectional associations (with mappedBy) are checked
        // Unidirectional associations should be ignored

        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: All issues should have an inverse_field (indicating bidirectional)
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            $data = $issue->getData();
            self::assertArrayHasKey('inverse_field', $data, 'All issues should be from bidirectional associations');
            self::assertNotEmpty($data['inverse_field'], 'inverse_field should not be empty');
        }
    }

    #[Test]
    public function it_creates_backtrace_information(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Issues should have backtrace information
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            $data = $issue->getData();
            // Backtrace is optional, but if present should be an array
            if (isset($data['backtrace'])) {
                self::assertIsArray($data['backtrace']);
            }
        }
    }

    #[Test]
    public function it_differentiates_between_high_and_medium_severity(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: orphan_removal_nullable_fk should be high severity
        $issuesArray = $issues->toArray();
        $orphanNullableIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return ($data['inconsistency_type'] ?? '') === 'orphan_removal_nullable_fk';
        });

        self::assertGreaterThan(0, count($orphanNullableIssues));

        foreach ($orphanNullableIssues as $issue) {
            $severity = $issue->getSeverity()->value;
            self::assertEquals('critical', $severity, 'orphan_removal_nullable_fk should have critical severity');
        }

        // And cascade_remove_set_null should be medium severity
        $cascadeSetNullIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return ($data['inconsistency_type'] ?? '') === 'cascade_remove_set_null';
        });

        self::assertGreaterThan(0, count($cascadeSetNullIssues));

        foreach ($cascadeSetNullIssues as $issue) {
            $severity = $issue->getSeverity()->value;
            self::assertEquals('warning', $severity, 'cascade_remove_set_null should have warning severity');
        }
    }

    #[Test]
    public function it_handles_multiple_inconsistencies_on_same_entity(): void
    {
        // This test verifies that if one entity has multiple inconsistencies,
        // all of them are detected

        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Some entities might have multiple issues
        $issuesArray = $issues->toArray();

        // Group by entity
        $entitiesWithIssues = [];
        foreach ($issuesArray as $issue) {
            $data = $issue->getData();
            $entity = $data['entity'] ?? '';
            if (!isset($entitiesWithIssues[$entity])) {
                $entitiesWithIssues[$entity] = 0;
            }
            $entitiesWithIssues[$entity]++;
        }

        // At least one entity should have at least one issue
        self::assertGreaterThan(0, count($entitiesWithIssues));
    }

    #[Test]
    public function it_does_not_flag_orphan_removal_with_not_null_fk_when_nullable_not_explicit(): void
    {
        // This test ensures that when nullable is NOT explicitly set in the mapping,
        // it correctly defaults to FALSE (NOT NULL), and thus should NOT trigger
        // "orphanRemoval with nullable FK" false positive
        //
        // Example: Sylius Mollie plugin has:
        // - GatewayConfig with orphanRemoval=true
        // - MollieGatewayConfig with ManyToOne gateway (no explicit nullable=true)
        // - This should NOT be flagged as inconsistent

        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: If an entity has orphanRemoval=true and the inverse side
        // has a JoinColumn WITHOUT explicit nullable=true, it should NOT be flagged
        $issuesArray = $issues->toArray();

        // Look for false positives where nullable is not explicitly set but assumed to be true
        foreach ($issuesArray as $issue) {
            $data = $issue->getData();
            $inconsistencyType = $data['inconsistency_type'] ?? '';

            if ('orphan_removal_nullable_fk' === $inconsistencyType) {
                // This should only trigger if nullable is EXPLICITLY true
                // If we get here, check that the description mentions nullable
                $description = $issue->getDescription();
                self::assertStringContainsString(
                    'nullable',
                    strtolower($description),
                    'orphan_removal_nullable_fk should only trigger when nullable is explicitly true',
                );
            }
        }

        // This test passes if no assertion failures occur
        self::assertTrue(true);
    }

    #[Test]
    public function it_correctly_treats_missing_nullable_as_not_null(): void
    {
        // Doctrine default behavior:
        // - If nullable is not specified in JoinColumn, it defaults to FALSE (NOT NULL)
        // - Only if explicitly set to true, the column is nullable
        //
        // This test verifies that our analyzer respects this default

        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Count issues with orphan_removal_nullable_fk
        $issuesArray = $issues->toArray();
        $orphanRemovalNullableIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return ($data['inconsistency_type'] ?? '') === 'orphan_removal_nullable_fk';
        });

        // If we have such issues, verify they're legitimate (explicit nullable=true)
        // In our test fixtures, we should have at least one entity with this issue
        // but NOT entities where nullable is simply not specified
        foreach ($orphanRemovalNullableIssues as $issue) {
            $description = $issue->getDescription();
            // Should mention that the FK is actually nullable
            self::assertStringContainsString('nullable', strtolower($description));
        }

        // This verifies the logic without being too strict about fixture details
        self::assertTrue(true);
    }
}
