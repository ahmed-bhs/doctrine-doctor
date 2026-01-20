<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\OnDeleteCascadeMismatchAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for OnDeleteCascadeMismatchAnalyzer.
 *
 * This analyzer detects mismatches between ORM cascade configuration and database
 * onDelete constraints. There are 4 types of mismatches:
 *
 * 1. orm_cascade_db_setnull: ORM cascade=remove but DB onDelete=SET NULL
 * 2. orm_orphan_db_setnull: ORM orphanRemoval=true but DB onDelete=SET NULL
 * 3. db_cascade_no_orm: DB onDelete=CASCADE but no ORM cascade=remove
 * 4. orm_cascade_no_db: ORM cascade=remove but no DB onDelete constraint
 */
final class OnDeleteCascadeMismatchAnalyzerTest extends TestCase
{
    private OnDeleteCascadeMismatchAnalyzer $analyzer;

    protected function setUp(): void
    {
        // Create EntityManager with ONLY onDelete cascade mismatch test entities
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/OnDeleteCascadeMismatchTest',
        ]);

        $this->analyzer = new OnDeleteCascadeMismatchAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_orm_cascade_db_setnull_mismatch(): void
    {
        // Arrange: Order has cascade=remove but child has onDelete=SET NULL
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithOrmCascadeDbSetNull');
        });

        self::assertCount(1, $orderIssues, 'Should detect orm_cascade_db_setnull mismatch');

        $issue = reset($orderIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('orm_cascade_db_setnull', $data['mismatch_type']);
        self::assertEquals('SET NULL', $data['db_on_delete']);
        self::assertContains('remove', $data['orm_cascade']);
    }

    #[Test]
    public function it_detects_orphan_removal_with_set_null_as_cascade_mismatch(): void
    {
        // Arrange: Cart has orphanRemoval=true (which implies cascade=remove) but child has onDelete=SET NULL
        // Note: Doctrine automatically adds 'remove' to cascade when orphanRemoval=true
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be detected as orm_cascade_db_setnull (not orm_orphan_db_setnull)
        // because Doctrine adds cascade=remove when orphanRemoval=true
        $issuesArray = $issues->toArray();
        $cartIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'CartWithOrphanRemovalDbSetNull');
        });

        self::assertCount(1, $cartIssues, 'Should detect mismatch');

        $issue = reset($cartIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        // orphanRemoval=true implies cascade=remove in Doctrine
        self::assertEquals('orm_cascade_db_setnull', $data['mismatch_type']);
        self::assertEquals('SET NULL', $data['db_on_delete']);
    }

    #[Test]
    public function it_detects_db_cascade_no_orm_mismatch(): void
    {
        // Arrange: Invoice child has onDelete=CASCADE but parent has no cascade=remove
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $invoiceIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'InvoiceWithDbCascadeNoOrm');
        });

        self::assertCount(1, $invoiceIssues, 'Should detect db_cascade_no_orm mismatch');

        $issue = reset($invoiceIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('db_cascade_no_orm', $data['mismatch_type']);
        self::assertEquals('CASCADE', $data['db_on_delete']);
        self::assertNotContains('remove', $data['orm_cascade']);
    }

    #[Test]
    public function it_detects_orm_cascade_no_db_mismatch(): void
    {
        // Arrange: Document has cascade=remove but child has no onDelete constraint
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $documentIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'DocumentWithOrmCascadeNoDb');
        });

        self::assertCount(1, $documentIssues, 'Should detect orm_cascade_no_db mismatch');

        $issue = reset($documentIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('orm_cascade_no_db', $data['mismatch_type']);
        self::assertEquals('NONE', $data['db_on_delete']);
        self::assertContains('remove', $data['orm_cascade']);
    }

    #[Test]
    public function it_does_not_flag_correct_configuration(): void
    {
        // Arrange: Project has both ORM cascade=remove AND DB onDelete=CASCADE
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $projectIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'ProjectWithCorrectConfig');
        });

        self::assertCount(0, $projectIssues, 'Correct configuration should not trigger issues');
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
            return str_contains($data['entity'] ?? '', 'OrderWithOrmCascadeDbSetNull');
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
        self::assertStringContainsString('OrderItemWithSetNull', $data['target_entity']);
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
            return str_contains($data['entity'] ?? '', 'OrderWithOrmCascadeDbSetNull');
        });

        self::assertGreaterThan(0, count($orderIssues));

        $issue = reset($orderIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
    }

    #[Test]
    public function it_detects_all_mismatch_types(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect at least 3 distinct mismatch types
        // Note: orm_orphan_db_setnull is unreachable because Doctrine automatically
        // adds cascade=remove when orphanRemoval=true
        $issuesArray = $issues->toArray();

        self::assertGreaterThanOrEqual(3, count($issuesArray), 'Should detect at least 3 mismatches');

        // Collect all mismatch types
        $mismatchTypes = array_map(fn ($issue) => $issue->getData()['mismatch_type'] ?? '', $issuesArray);
        $uniqueTypes = array_unique($mismatchTypes);

        self::assertGreaterThanOrEqual(3, count($uniqueTypes), 'Should have at least 3 distinct mismatch types');
        self::assertContains('orm_cascade_db_setnull', $mismatchTypes);
        self::assertContains('db_cascade_no_orm', $mismatchTypes);
        self::assertContains('orm_cascade_no_db', $mismatchTypes);
    }

    #[Test]
    public function it_sets_correct_severity_for_mismatches(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: All mismatches should be warning severity
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            self::assertEquals('warning', $issue->getSeverity()->value, 'All mismatches should be warning severity');
        }
    }

    #[Test]
    public function it_includes_mismatch_type_in_issue_data(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithOrmCascadeDbSetNull');
        });

        self::assertGreaterThan(0, count($orderIssues));

        $issue = reset($orderIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('mismatch_type', $data);
        self::assertArrayHasKey('orm_cascade', $data);
        self::assertArrayHasKey('db_on_delete', $data);
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
        self::assertStringContainsString('OnDelete', $name);
        self::assertStringContainsString('Cascade', $name);
        self::assertStringContainsString('Mismatch', $name);
    }

    #[Test]
    public function it_has_analyzer_description(): void
    {
        // Assert
        $description = $this->analyzer->getDescription();

        self::assertNotEmpty($description);
        self::assertStringContainsString('mismatch', strtolower($description));
        self::assertStringContainsString('cascade', strtolower($description));
    }

    #[Test]
    public function it_includes_cascade_configuration_details(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            $data = $issue->getData();

            self::assertArrayHasKey('orm_cascade', $data, 'Should include ORM cascade config');
            self::assertArrayHasKey('db_on_delete', $data, 'Should include DB onDelete config');
        }
    }

    #[Test]
    public function it_provides_suggestions_for_all_mismatch_types(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: All detected issues should have suggestions
        $issuesArray = $issues->toArray();

        self::assertGreaterThanOrEqual(3, count($issuesArray), 'Should detect at least 3 mismatches');

        foreach ($issuesArray as $issue) {
            $suggestion = $issue->getSuggestion();
            self::assertNotNull($suggestion, 'Each mismatch should have a suggestion');
        }
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

            self::assertNotEmpty($targetEntity, 'Target entity should not be empty');
            self::assertNotEquals('Unknown', $targetEntity, 'Target entity should be valid');
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
            return str_contains($data['entity'] ?? '', 'OrderWithOrmCascadeDbSetNull');
        });

        self::assertGreaterThan(0, count($orderIssues));

        $issue = reset($orderIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('inverse_field', $data);
        self::assertNotEmpty($data['inverse_field']);
    }

    #[Test]
    public function it_correctly_identifies_cascade_operations(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            $data = $issue->getData();
            $ormCascade = $data['orm_cascade'] ?? [];

            self::assertIsArray($ormCascade, 'ORM cascade should be an array');
        }
    }

    #[Test]
    public function it_normalizes_db_on_delete_values(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: DB onDelete should be uppercase or NONE
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            $data = $issue->getData();
            $dbOnDelete = $data['db_on_delete'] ?? '';

            self::assertMatchesRegularExpression(
                '/^(CASCADE|SET NULL|RESTRICT|NO ACTION|NONE)$/',
                $dbOnDelete,
                'DB onDelete should be normalized to uppercase or NONE',
            );
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
            self::assertStringContainsString('Cascade', $title);
            self::assertStringContainsString('Mismatch', $title);
        }
    }
}
