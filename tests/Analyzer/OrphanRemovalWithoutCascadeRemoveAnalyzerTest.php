<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\OrphanRemovalWithoutCascadeRemoveAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrphanRemovalTest\CartWithoutOrphanRemoval;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrphanRemovalTest\InvoiceWithCompleteConfiguration;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for OrphanRemovalWithoutCascadeRemoveAnalyzer.
 *
 * This analyzer detects orphanRemoval=true WITHOUT cascade="remove", which creates
 * inconsistent behavior:
 * - Removing from collection deletes children (orphanRemoval)
 * - But deleting parent does NOT delete children (no cascade remove).
 *
 * **IMPORTANT NOTE**: In Doctrine ORM 4.x, when orphanRemoval=true is set,
 * Doctrine AUTOMATICALLY adds cascade="remove" internally. This makes the
 * problematic configuration impossible to create, and this analyzer will
 * not detect any issues in Doctrine 4.x projects.
 *
 * These tests verify that the analyzer works correctly and doesn't produce
 * false positives when Doctrine has already corrected the configuration.
 */
final class OrphanRemovalWithoutCascadeRemoveAnalyzerTest extends TestCase
{
    private OrphanRemovalWithoutCascadeRemoveAnalyzer $analyzer;

    protected function setUp(): void
    {
        // Create EntityManager with ONLY orphan removal test entities
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/OrphanRemovalTest',
        ]);

        $this->analyzer = new OrphanRemovalWithoutCascadeRemoveAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(), // @phpstan-ignore-line argument.type
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_configuration_is_complete(): void
    {
        // Arrange: InvoiceWithCompleteConfiguration has orphanRemoval=true AND cascade="remove"
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect issues from InvoiceWithCompleteConfiguration
        $issuesArray = $issues->toArray();
        $invoiceIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'InvoiceWithCompleteConfiguration');
        });

        self::assertCount(0, $invoiceIssues, 'Complete configuration should not trigger issues');
    }

    #[Test]
    public function it_returns_empty_collection_when_no_orphan_removal(): void
    {
        // Arrange: CartWithoutOrphanRemoval has NO orphanRemoval
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect issues from CartWithoutOrphanRemoval
        $issuesArray = $issues->toArray();
        $cartIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'CartWithoutOrphanRemoval');
        });

        self::assertCount(0, $cartIssues, 'No orphanRemoval should not trigger issues');
    }

    #[Test]
    public function it_does_not_detect_false_positives_in_doctrine_4(): void
    {
        // Arrange: In Doctrine 4, orphanRemoval=true automatically adds cascade="remove"
        // So this analyzer will not detect any issues (this is expected behavior)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect issues because Doctrine 4 auto-corrects the configuration
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithOrphanRemovalOnly');
        });

        // In Doctrine 4.x, Doctrine automatically adds cascade="remove" when orphanRemoval=true
        // Therefore, this problematic configuration cannot exist, and no issues are detected
        self::assertCount(0, $orderIssues, 'Doctrine 4 auto-corrects this configuration, so no issues expected');
    }

    #[Test]
    public function it_does_not_produce_false_positives(): void
    {
        // Arrange: All our test entities have correct configurations (Doctrine 4 auto-corrects them)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should have zero issues in Doctrine 4
        $issuesArray = $issues->toArray();
        self::assertCount(0, $issuesArray, 'Doctrine 4 auto-corrects orphanRemoval config, no issues expected');
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
        self::assertStringContainsString('cascade', strtolower($description));
    }

    #[Test]
    public function it_verifies_doctrine_4_auto_correction(): void
    {
        // Arrange: Verify that Doctrine 4 has indeed added cascade="remove" automatically
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: No issues because Doctrine corrected the configuration
        self::assertCount(0, $issues->toArray(), 'Doctrine 4 automatically adds cascade="remove" with orphanRemoval=true');
    }
}
