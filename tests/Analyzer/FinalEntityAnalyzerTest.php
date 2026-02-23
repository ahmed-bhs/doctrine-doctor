<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\FinalEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\FinalEntityTest\FinalEntityWithEagerAssociations;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\FinalEntityTest\FinalEntityWithLazyAssociations;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\FinalEntityTest\FinalEntityWithNoAssociations;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\FinalEntityTest\FinalEntityWithOnlyIds;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\FinalEntityTest\NonFinalEntity;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for FinalEntityAnalyzer.
 *
 * Tests detection of:
 * - Final entities with lazy associations (CRITICAL)
 * - Final entities with eager associations (WARNING)
 * - Final entities with no associations (WARNING)
 * - Non-final entities (should NOT be flagged)
 */
final class FinalEntityAnalyzerTest extends TestCase
{
    private EntityManager $entityManager;

    private FinalEntityAnalyzer $analyzer;

    protected function setUp(): void
    {
        // Create in-memory entity manager with specific fixtures
        $configuration = PlatformAnalyzerTestHelper::createTestConfiguration([__DIR__ . '/../Fixtures/Entity/FinalEntityTest']);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->entityManager = new EntityManager($connection, $configuration);
        $this->analyzer = new FinalEntityAnalyzer(
            $this->entityManager,
            new IssueFactory(),
        );
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        self::assertInstanceOf(AnalyzerInterface::class, $this->analyzer);
    }

    #[Test]
    public function it_uses_generator_pattern(): void
    {
        $issues = $this->analyzer->analyze(QueryDataCollection::empty());

        self::assertInstanceOf(\Generator::class, $issues->getIterator());
    }

    #[Test]
    public function it_detects_final_entity_with_lazy_associations_as_critical(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        $finalLazyIssues = array_filter(
            $issues,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'FinalEntityWithLazyAssociations'),
        );

        // Should detect the final entity with lazy associations
        self::assertCount(1, $finalLazyIssues);

        $issue = reset($finalLazyIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals(Severity::critical(), $issue->getSeverity());
        self::assertStringContainsString('final', $issue->getDescription());
        self::assertStringContainsString('proxy', $issue->getDescription());
        self::assertStringContainsString('lazy', $issue->getDescription());
    }

    #[Test]
    public function it_detects_final_entity_with_no_associations_as_warning(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        $finalNoAssocIssues = array_filter(
            $issues,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'FinalEntityWithNoAssociations'),
        );

        // Should detect the final entity with no associations
        self::assertCount(1, $finalNoAssocIssues);

        $issue = reset($finalNoAssocIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals(Severity::warning(), $issue->getSeverity());
        self::assertStringContainsString('final', $issue->getDescription());
    }

    #[Test]
    public function it_detects_final_entity_with_eager_associations_as_warning(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        $finalEagerIssues = array_filter(
            $issues,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'FinalEntityWithEagerAssociations'),
        );

        // Should detect the final entity with eager associations
        self::assertCount(1, $finalEagerIssues);

        $issue = reset($finalEagerIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals(Severity::warning(), $issue->getSeverity());
        self::assertStringContainsString('final', $issue->getDescription());
    }

    #[Test]
    public function it_detects_final_entity_with_only_ids(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        $finalIdsIssues = array_filter(
            $issues,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'FinalEntityWithOnlyIds'),
        );

        // Should detect the final entity even without object associations
        self::assertCount(1, $finalIdsIssues);

        $issue = reset($finalIdsIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals(Severity::warning(), $issue->getSeverity());
    }

    #[Test]
    public function it_does_not_flag_non_final_entities(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        $nonFinalIssues = array_filter(
            $issues,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'NonFinalEntity'),
        );

        // Should NOT flag non-final entities
        self::assertCount(0, $nonFinalIssues);
    }

    #[Test]
    public function it_lists_lazy_associations_in_critical_issues(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        $criticalIssues = array_filter(
            $issues,
            fn ($issue) => $issue->getSeverity() === Severity::critical(),
        );

        foreach ($criticalIssues as $issue) {
            $description = $issue->getDescription();

            // Should mention the number of lazy associations
            self::assertMatchesRegularExpression(
                '/\d+ lazy-loaded association/i',
                $description,
                'Critical issues should list lazy associations',
            );
        }
    }

    #[Test]
    public function it_provides_solution_to_remove_final_keyword(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        foreach ($issues as $issue) {
            $description = $issue->getDescription();

            // Should provide clear solution
            self::assertStringContainsString('Remove', $description);
            self::assertStringContainsString('final', $description);
            self::assertTrue(
                str_contains($description, 'Solution') ||
                str_contains($description, 'Alternative'),
                'Should provide solution or alternative',
            );
        }
    }

    #[Test]
    public function it_suggests_alternatives_for_immutability(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        foreach ($issues as $issue) {
            $description = $issue->getDescription();

            // Should suggest alternatives to final for immutability
            self::assertTrue(
                str_contains($description, 'Alternative') ||
                str_contains($description, 'readonly') ||
                str_contains($description, 'eager loading') ||
                str_contains($description, 'methods as final'),
                'Should suggest alternatives to final keyword',
            );
        }
    }

    #[Test]
    public function it_provides_correct_severity_based_on_associations(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        // Group issues by severity
        $criticalCount = 0;
        $warningCount = 0;

        foreach ($issues as $issue) {
            if ($issue->getSeverity() === Severity::critical()) {
                $criticalCount++;
                // Critical issues should mention lazy associations
                self::assertStringContainsString('lazy', $issue->getDescription());
            } elseif ($issue->getSeverity() === Severity::warning()) {
                $warningCount++;
            }
        }

        // Should have 1 CRITICAL (FinalEntityWithLazyAssociations)
        // Should have 3 WARNINGS (FinalEntityWithNoAssociations, FinalEntityWithEagerAssociations, FinalEntityWithOnlyIds)
        self::assertGreaterThanOrEqual(1, $criticalCount, 'Should have at least 1 critical issue');
        self::assertGreaterThanOrEqual(3, $warningCount, 'Should have at least 3 warning issues');
    }

    #[Test]
    public function it_includes_entity_name_in_titles(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        foreach ($issues as $issue) {
            $title = $issue->getTitle();

            // Title should contain 'Final Entity Detected'
            self::assertStringContainsString('Final Entity', $title);

            // Title should contain entity class name
            self::assertMatchesRegularExpression('/Final Entity.*: \w+/', $title);
        }
    }

    #[Test]
    public function it_explains_why_final_is_problematic(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        foreach ($issues as $issue) {
            $description = $issue->getDescription();

            // Should explain the problem
            self::assertTrue(
                str_contains($description, 'proxy') ||
                str_contains($description, 'lazy loading') ||
                str_contains($description, 'cannot be extended'),
                'Should explain why final is problematic for Doctrine',
            );
        }
    }

    #[Test]
    public function it_handles_empty_metadata_gracefully(): void
    {
        // Create entity manager with no entities
        $configuration = PlatformAnalyzerTestHelper::createTestConfiguration([__DIR__ . '/../Fixtures/NonExistentPath']);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $emptyEm = new EntityManager($connection, $configuration);
        $analyzer = new FinalEntityAnalyzer($emptyEm, new IssueFactory());

        $issues = $analyzer->analyze(QueryDataCollection::empty());

        // Should not crash, just return empty collection
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_uses_consistent_issue_format(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        foreach ($issues as $issue) {
            // Every issue must have required fields
            self::assertNotEmpty($issue->getTitle());
            self::assertNotEmpty($issue->getDescription());
            self::assertInstanceOf(Severity::class, $issue->getSeverity());

            // Queries should be empty array (metadata analyzer)
            self::assertIsArray($issue->getQueries());
            self::assertCount(0, $issue->getQueries());
        }
    }

    #[Test]
    public function it_does_not_duplicate_issues_for_same_entity(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        // Each entity should only be reported once
        $entityNames = array_map(function ($issue) {
            // Extract entity name from title
            if (1 === preg_match('/Final Entity.*?: (\w+)/', $issue->getTitle(), $matches)) {
                return $matches[1];
            }
            return $issue->getTitle();
        }, $issues);

        $entityCounts = array_count_values($entityNames);

        foreach ($entityCounts as $entityName => $count) {
            self::assertEquals(
                1,
                $count,
                "Entity should only be reported once: {$entityName}",
            );
        }
    }

    #[Test]
    public function it_returns_consistent_results_on_repeated_analysis(): void
    {
        $issues1 = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));
        $issues2 = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        self::assertCount(count($issues1), $issues2, 'Should return consistent number of issues');

        // Titles should match
        $titles1 = array_map(fn ($i) => $i->getTitle(), $issues1);
        $titles2 = array_map(fn ($i) => $i->getTitle(), $issues2);

        sort($titles1);
        sort($titles2);

        self::assertEquals($titles1, $titles2, 'Should return same issues on repeated analysis');
    }

    #[Test]
    public function it_counts_correct_number_of_issues(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        // Expected issues:
        // 1. FinalEntityWithLazyAssociations (CRITICAL)
        // 2. FinalEntityWithEagerAssociations (WARNING)
        // 3. FinalEntityWithNoAssociations (WARNING)
        // 4. FinalEntityWithOnlyIds (WARNING)
        // 5. NonFinalEntity (should NOT be flagged)
        // 6. RelatedEntity (should NOT be flagged - not final)

        // Total: 4 issues
        self::assertCount(4, $issues);
    }

    #[Test]
    public function it_includes_file_path_in_descriptions(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        foreach ($issues as $issue) {
            $description = $issue->getDescription();

            // Should include file path or reference to file
            self::assertTrue(
                str_contains($description, 'File:') ||
                str_contains($description, '.php') ||
                str_contains($description, 'class'),
                'Should reference file or class location',
            );
        }
    }

    #[Test]
    public function it_handles_metadata_loading_errors_gracefully(): void
    {
        // Create entity manager with invalid configuration
        $configuration = PlatformAnalyzerTestHelper::createTestConfiguration([]);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $emptyEm = new EntityManager($connection, $configuration);
        $analyzer = new FinalEntityAnalyzer($emptyEm, new IssueFactory());

        // Should not throw exception
        $issues = $analyzer->analyze(QueryDataCollection::empty());

        self::assertIsObject($issues);
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_correctly_identifies_severity_levels(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        $validSeverities = [Severity::critical(), Severity::warning()];

        foreach ($issues as $issue) {
            self::assertContains(
                $issue->getSeverity(),
                $validSeverities,
                'Issue severity must be critical or warning',
            );
        }
    }
}
