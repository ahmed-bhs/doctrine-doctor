<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Security\SQLInjectionInRawQueriesAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\EntityWithVulnerableMethods;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for SQLInjectionInRawQueriesAnalyzer.
 *
 * This analyzer detects SQL injection vulnerabilities in raw SQL queries.
 * It analyzes source code for dangerous patterns like string concatenation,
 * variable interpolation, and missing parameter binding.
 */
final class SQLInjectionInRawQueriesAnalyzerTest extends TestCase
{
    private SQLInjectionInRawQueriesAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity',
        ]);

        $this->analyzer = new SQLInjectionInRawQueriesAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_string_concatenation_vulnerability(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect concatenation in VulnerableRepository::findByNameUnsafe()
        $issuesArray = $issues->toArray();
        $concatenationIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getDescription(), 'findByNameUnsafe') &&
            str_contains((string) $issue->getDescription(), 'concatenation'));

        self::assertNotNull($concatenationIssue, 'Should detect string concatenation SQL injection');
        self::assertEquals('critical', $concatenationIssue->getSeverity()->value);
        self::assertStringContainsString('SQL injection', $concatenationIssue->getDescription());
        self::assertStringContainsString('NEVER concatenate', $concatenationIssue->getDescription());
    }

    #[Test]
    public function it_detects_variable_interpolation_vulnerability(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect interpolation in multiple methods
        $issuesArray = $issues->toArray();
        $interpolationIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'interpolation'),
        );

        self::assertGreaterThan(0, count($interpolationIssues), 'Should detect variable interpolation');

        $issue = array_values($interpolationIssues)[0];
        self::assertEquals('critical', $issue->getSeverity()->value);
        self::assertStringContainsString('SQL injection', $issue->getDescription());
    }

    #[Test]
    public function it_detects_missing_parameters_vulnerability(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect missing parameters in searchUnsafe()
        $issuesArray = $issues->toArray();
        $missingParamsIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getDescription(), 'searchUnsafe') ||
            (str_contains((string) $issue->getDescription(), 'no parameter binding') ||
             str_contains((string) $issue->getDescription(), 'dynamically built')));

        self::assertNotNull($missingParamsIssue, 'Should detect missing parameter binding');
        self::assertEquals('critical', $missingParamsIssue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_sprintf_vulnerability(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect sprintf in findByEmailUnsafe()
        $issuesArray = $issues->toArray();
        $sprintfIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getDescription(), 'sprintf'));

        self::assertNotNull($sprintfIssue, 'Should detect sprintf SQL injection');
        self::assertEquals('critical', $sprintfIssue->getSeverity()->value);
        self::assertStringContainsString('sprintf() does NOT escape', $sprintfIssue->getDescription());
    }

    #[Test]
    public function it_detects_vulnerabilities_in_entity_methods(): void
    {
        // Arrange: EntityWithVulnerableMethods has SQL injection in entity methods
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect issues in entity methods too (not just repositories)
        $issuesArray = $issues->toArray();
        $entityIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'EntityWithVulnerableMethods'),
        );

        self::assertGreaterThan(0, count($entityIssues), 'Should detect SQL injection in entity methods');
    }

    #[Test]
    public function it_does_not_flag_parameterized_queries(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Safe methods should NOT be flagged
        $issuesArray = $issues->toArray();
        $safeMethodIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'Safe'),
        );

        self::assertCount(0, $safeMethodIssues, 'Parameterized queries should not be flagged');
    }

    #[Test]
    public function it_does_not_flag_query_builder(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Query builder methods should NOT be flagged
        $issuesArray = $issues->toArray();
        $queryBuilderIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'findByIdSafe'),
        );

        self::assertCount(0, $queryBuilderIssues, 'Query builder should not be flagged');
    }

    #[Test]
    public function it_provides_parameterized_query_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Issues should have suggestions with proper parameter binding examples
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion, 'Should provide suggestion');
        self::assertStringContainsString('parameterized', strtolower($suggestion->getDescription()));
    }

    #[Test]
    public function it_includes_backtrace_information(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Issues should have backtrace with file and line
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];
        $backtrace = $issue->getBacktrace();

        self::assertNotNull($backtrace);
        self::assertArrayHasKey('file', $backtrace);
        self::assertArrayHasKey('line', $backtrace);
        self::assertStringContainsString('.php', $backtrace['file']);
        self::assertIsInt($backtrace['line']);
    }

    #[Test]
    public function it_checks_both_entities_and_repositories(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should find issues in both VulnerableRepository and EntityWithVulnerableMethods
        $issuesArray = $issues->toArray();

        $repoIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'VulnerableRepository'),
        );

        $entityIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'EntityWithVulnerableMethods'),
        );

        self::assertGreaterThan(0, count($repoIssues), 'Should check repositories');
        self::assertGreaterThan(0, count($entityIssues), 'Should check entities');
    }

    #[Test]
    public function it_detects_multiple_vulnerabilities_in_same_class(): void
    {
        // Arrange: VulnerableRepository has 4 vulnerable methods
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect all 4 vulnerabilities in VulnerableRepository
        $issuesArray = $issues->toArray();
        $repoIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'VulnerableRepository'),
        );

        self::assertGreaterThanOrEqual(4, count($repoIssues), 'Should detect all vulnerable methods in repository');
    }

    #[Test]
    public function it_explains_security_impact(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Descriptions should explain security impact
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];
        $description = $issue->getDescription();

        // Should mention SQL injection and its consequences
        self::assertStringContainsString('SQL injection', $description);
        self::assertTrue(
            str_contains($description, 'read') ||
            str_contains($description, 'modify') ||
            str_contains($description, 'delete') ||
            str_contains($description, 'malicious'),
            'Should explain what attackers can do',
        );
    }

    #[Test]
    public function it_has_correct_analyzer_metadata(): void
    {
        // No getName() or getCategory() in this analyzer based on the code
        // Just verify it runs without errors
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        self::assertIsObject($issues);
    }

    #[Test]
    public function it_handles_exceptions_gracefully(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act: Even with potential reflection errors, should not throw
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should return valid collection
        self::assertIsObject($issues);
        $issuesArray = $issues->toArray();
        self::assertIsArray($issuesArray);
    }
}
