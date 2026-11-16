<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\EntityStateConsistencyAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for EntityStateConsistencyAnalyzer.
 *
 * NOTE: These are basic "smoke tests" that verify the analyzer doesn't crash.
 * They do NOT test the specific logic of this analyzer.
 *
 * For comprehensive testing of the analyzer's detection logic, see:
 * - Integration tests in tests/Integration/Analyzer/EntityStateConsistencyAnalyzerIntegrationTest.php
 *
 * TODO: Add specific unit tests that verify:
 * - Detection of specific issues this analyzer is designed to find
 * - Edge cases and boundary conditions
 * - Correct severity and category assignment
 * - Proper suggestion generation
 */
final class EntityStateConsistencyAnalyzerTest extends TestCase
{
    private EntityStateConsistencyAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager();

        $this->analyzer = new EntityStateConsistencyAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
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
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
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
    public function it_reports_a_new_entity_attached_to_a_required_association_without_cascade(): void
    {
        $issues = $this->issuesForRequiredAssociationFixtures();

        // Invoice::$customer is left out: Shipment::$customer cascades, and setCustomer() may target either.
        self::assertSame(['New Entity in Required Association: Payment::$invoice'], array_map(static fn ($issue): string => $issue->getTitle(), $issues));
        self::assertStringContainsString('InvoiceFactory::deposit() attaches a new Invoice', (string) $issues[0]->getDescription());
        $source = (string) file_get_contents(__DIR__ . '/../Fixtures/RequiredAssociationProject/App/InvoiceFactory.php');
        $line = substr_count(strstr($source, 'setInvoice(new Invoice', true) ?: '', "\n") + 1;
        self::assertSame($line, $issues[0]->getData()['line'] ?? null);
    }

    #[Test]
    public function it_reports_nothing_without_the_application_code(): void
    {
        $analyzer = new EntityStateConsistencyAnalyzer(
            PlatformAnalyzerTestHelper::createTestEntityManager([__DIR__ . '/../Fixtures/Entity/RequiredAssociationTest']),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        self::assertCount(0, $analyzer->analyze(QueryDataCollection::empty()));
    }

    /**
     * @return list<\AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function issuesForRequiredAssociationFixtures(): array
    {
        $analyzer = new EntityStateConsistencyAnalyzer(
            PlatformAnalyzerTestHelper::createTestEntityManager([__DIR__ . '/../Fixtures/Entity/RequiredAssociationTest']),
            PlatformAnalyzerTestHelper::createIssueFactory(),
            new \AhmedBhs\DoctrineDoctor\Analyzer\Helper\ProjectSourceScanner(__DIR__ . '/../Fixtures/RequiredAssociationProject', ['App']),
        );

        return array_values($analyzer->analyze(QueryDataCollection::empty())->toArray());
    }
}
