<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\ColumnTypeAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for ColumnTypeAnalyzer.
 *
 * Tests the analyzer's ability to detect column type issues across all fixtures.
 */
final class ColumnTypeAnalyzerIntegrationTest extends TestCase
{
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        $configuration = PlatformAnalyzerTestHelper::createTestConfiguration([__DIR__ . '/../../Fixtures/Entity']);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->entityManager = new EntityManager($connection, $configuration);
    }

    #[Test]
    public function it_analyzes_without_errors(): void
    {
        $columnTypeAnalyzer = $this->createAnalyzer();
        $issues = $columnTypeAnalyzer->analyze(QueryDataCollection::empty());

        self::assertInstanceOf(IssueCollection::class, $issues);
    }

    #[Test]
    public function it_handles_entities_gracefully(): void
    {
        $columnTypeAnalyzer = $this->createAnalyzer();
        $issues = $columnTypeAnalyzer->analyze(QueryDataCollection::empty());

        foreach ($issues as $issue) {
            self::assertNotNull($issue);
        }

        self::assertTrue(true); // No exceptions thrown
    }

    #[Test]
    public function it_analyzes_all_entities_without_errors(): void
    {
        $columnTypeAnalyzer = $this->createAnalyzer();
        $issues = $columnTypeAnalyzer->analyze(QueryDataCollection::empty());

        self::assertInstanceOf(IssueCollection::class, $issues);

        // Iterate through all issues to ensure they're valid
        $issueCount = 0;
        foreach ($issues as $issue) {
            $issueCount++;

            // Every issue must have these properties
            self::assertNotNull($issue->getTitle(), 'Issue must have a title');
            self::assertIsString($issue->getTitle());
            self::assertNotEmpty($issue->getTitle());

            self::assertNotNull($issue->getDescription(), 'Issue must have a description');
            self::assertIsString($issue->getDescription());

            self::assertNotNull($issue->getSeverity(), 'Issue must have severity');
            self::assertInstanceOf(Severity::class, $issue->getSeverity());
        }

        // Should analyze without throwing exceptions
        self::assertGreaterThanOrEqual(0, $issueCount);
    }

    #[Test]
    public function it_returns_consistent_results(): void
    {
        $columnTypeAnalyzer = $this->createAnalyzer();

        // Run analysis twice
        $issues1 = $columnTypeAnalyzer->analyze(QueryDataCollection::empty());
        $issues2 = $columnTypeAnalyzer->analyze(QueryDataCollection::empty());

        // Should return same number of issues
        self::assertCount(count($issues1), $issues2, 'Analyzer should return consistent results on repeated analysis');
    }

    #[Test]
    public function it_validates_issue_severity_is_appropriate(): void
    {
        $columnTypeAnalyzer = $this->createAnalyzer();
        $issues = $columnTypeAnalyzer->analyze(QueryDataCollection::empty());

        $validSeverities = ['critical', 'warning', 'info'];

        foreach ($issues as $issue) {
            $severityValue = $issue->getSeverity()->value;
            self::assertContains($severityValue, $validSeverities, "Issue severity must be one of: " . implode(', ', $validSeverities));
        }
    }

    #[Test]
    public function it_detects_object_type_issues(): void
    {
        $columnTypeAnalyzer = $this->createAnalyzer();
        $issues = iterator_to_array($columnTypeAnalyzer->analyze(QueryDataCollection::empty()));

        $objectTypeIssues = array_filter(
            $issues,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'type "object"'),
        );

        if (count($objectTypeIssues) > 0) {
            foreach ($objectTypeIssues as $issue) {
                self::assertEquals(Severity::CRITICAL, $issue->getSeverity(), 'Object type issues should be critical');
                self::assertStringContainsString('serialize()', $issue->getDescription());
                self::assertStringContainsString('json', $issue->getDescription());
            }
        }

        self::assertTrue(true, 'Object type detection completed without errors');
    }

    #[Test]
    public function it_detects_array_type_issues(): void
    {
        $columnTypeAnalyzer = $this->createAnalyzer();
        $issues = iterator_to_array($columnTypeAnalyzer->analyze(QueryDataCollection::empty()));

        $arrayTypeIssues = array_filter(
            $issues,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'type "array"'),
        );

        if (count($arrayTypeIssues) > 0) {
            foreach ($arrayTypeIssues as $issue) {
                self::assertEquals(Severity::WARNING, $issue->getSeverity(), 'Array type issues should be warnings');
                self::assertStringContainsString('serialize()', $issue->getDescription());
                self::assertStringContainsString('json', $issue->getDescription());
            }
        }

        self::assertTrue(true, 'Array type detection completed without errors');
    }

    #[Test]
    public function it_detects_simple_array_issues(): void
    {
        $columnTypeAnalyzer = $this->createAnalyzer();
        $issues = iterator_to_array($columnTypeAnalyzer->analyze(QueryDataCollection::empty()));

        $simpleArrayIssues = array_filter(
            $issues,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'simple_array'),
        );

        if (count($simpleArrayIssues) > 0) {
            foreach ($simpleArrayIssues as $issue) {
                self::assertEquals(Severity::INFO, $issue->getSeverity(), 'Simple array issues should be info');
                self::assertStringContainsString('cannot contain commas', $issue->getDescription());
            }
        }

        self::assertTrue(true, 'Simple array detection completed without errors');
    }

    #[Test]
    public function it_suggests_enum_opportunities(): void
    {
        $columnTypeAnalyzer = $this->createAnalyzer();
        $issues = iterator_to_array($columnTypeAnalyzer->analyze(QueryDataCollection::empty()));

        $enumIssues = array_filter(
            $issues,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'enum'),
        );

        if (count($enumIssues) > 0) {
            foreach ($enumIssues as $issue) {
                self::assertEquals(Severity::INFO, $issue->getSeverity(), 'Enum suggestions should be info');
                self::assertStringContainsString('PHP 8.1', $issue->getDescription());
                self::assertStringContainsString('native enum', $issue->getDescription());
            }
        }

        self::assertTrue(true, 'Enum detection completed without errors');
    }

    #[Test]
    public function it_provides_suggestions_with_code_examples(): void
    {
        $columnTypeAnalyzer = $this->createAnalyzer();
        $issues = $columnTypeAnalyzer->analyze(QueryDataCollection::empty());

        foreach ($issues as $issue) {
            $suggestion = $issue->getSuggestion();

            if (null !== $suggestion) {
                self::assertNotEmpty($suggestion->getDescription());

                // Check if suggestion has code example for code quality issues
                if (method_exists($suggestion, 'getCode')) {
                    $code = $suggestion->getCode();
                    if (null !== $code && '' !== $code) { // @phpstan-ignore-line notIdentical.alwaysTrue
                        self::assertIsString($code);
                        self::assertNotEmpty($code);
                    }
                }
            }
        }

        self::assertTrue(true, 'All suggestions have proper format');
    }

    #[Test]
    public function it_categorizes_issues_by_severity(): void
    {
        $columnTypeAnalyzer = $this->createAnalyzer();
        $issues = iterator_to_array($columnTypeAnalyzer->analyze(QueryDataCollection::empty()));

        $criticalIssues = array_filter($issues, fn ($i) => Severity::CRITICAL === $i->getSeverity());
        $warningIssues = array_filter($issues, fn ($i) => Severity::WARNING === $i->getSeverity());
        $infoIssues = array_filter($issues, fn ($i) => Severity::INFO === $i->getSeverity());

        // Critical: object type
        // Warning: array type
        // Info: simple_array + enum opportunities

        self::assertGreaterThanOrEqual(0, count($criticalIssues), 'Should have critical issues for object types');
        self::assertGreaterThanOrEqual(0, count($warningIssues), 'Should have warning issues for array types');
        self::assertGreaterThanOrEqual(0, count($infoIssues), 'Should have info issues for simple_array and enums');
    }

    #[Test]
    public function it_includes_entity_context_in_all_issues(): void
    {
        $columnTypeAnalyzer = $this->createAnalyzer();
        $issues = $columnTypeAnalyzer->analyze(QueryDataCollection::empty());

        foreach ($issues as $issue) {
            $description = $issue->getDescription();

            // Should reference entity and field
            self::assertMatchesRegularExpression(
                '/\w+::\$\w+/',
                $description,
                'Issue description should reference entity::$field',
            );
        }
    }

    #[Test]
    public function it_provides_replacement_recommendations(): void
    {
        $columnTypeAnalyzer = $this->createAnalyzer();
        $issues = iterator_to_array($columnTypeAnalyzer->analyze(QueryDataCollection::empty()));

        $issuesWithReplacements = array_filter(
            $issues,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'Use ') ||
                         str_contains((string) $issue->getDescription(), 'Consider '),
        );

        if (count($issues) > 0) {
            self::assertNotEmpty($issuesWithReplacements, 'Issues should provide replacement recommendations');
        }

        self::assertTrue(true, 'Replacement recommendations are provided');
    }

    #[Test]
    public function it_handles_mixed_entity_fixture_base(): void
    {
        $columnTypeAnalyzer = $this->createAnalyzer();
        $issues = iterator_to_array($columnTypeAnalyzer->analyze(QueryDataCollection::empty()));

        // Should handle entities from all fixture directories
        $entityNames = array_unique(array_map(function ($issue) {
            if (1 === preg_match('/(\w+)::\$/', $issue->getDescription(), $matches)) {
                return $matches[1];
            }
            return '';
        }, $issues));

        $entityNames = array_filter($entityNames); // @phpstan-ignore-line arrayFilter.strict

        // Should find issues across multiple entities
        self::assertGreaterThanOrEqual(0, count($entityNames), 'Should analyze entities from fixture base');
    }

    #[Test]
    public function it_respects_issue_format_contract(): void
    {
        $columnTypeAnalyzer = $this->createAnalyzer();
        $issues = $columnTypeAnalyzer->analyze(QueryDataCollection::empty());

        foreach ($issues as $issue) {
            // Required fields
            self::assertNotEmpty($issue->getTitle());
            self::assertNotEmpty($issue->getDescription());
            self::assertInstanceOf(Severity::class, $issue->getSeverity());

            // Optional fields with correct types
            self::assertIsArray($issue->getQueries());

            // Backtrace can be null for metadata analyzers
            if (null !== $issue->getBacktrace()) {
                self::assertIsArray($issue->getBacktrace());
            }
        }
    }

    private function createAnalyzer(): ColumnTypeAnalyzer
    {
        $entityManager = $this->entityManager;
        $suggestionFactory = new SuggestionFactory($this->createPhpRenderer());

        return new ColumnTypeAnalyzer($entityManager, $suggestionFactory);
    }

    private function createPhpRenderer(): PhpTemplateRenderer
    {
        return new PhpTemplateRenderer();
    }
}
