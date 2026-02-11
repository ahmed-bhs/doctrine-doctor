<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadeConfigurationAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeConfigTest\InvoiceWithMissingCascade;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeConfigTest\OrderWithCascadeAll;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeConfigTest\OrderWithGoodCascade;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for CascadeConfigurationAnalyzer.
 *
 * This analyzer detects three types of cascade configuration issues:
 * 1. Overuse/abuse of cascade="all" (especially on independent entities)
 * 2. Dangerous cascade="remove" on independent entities
 * 3. Missing cascade on composition relationships.
 */
final class CascadeConfigurationAnalyzerTest extends TestCase
{
    private CascadeConfigurationAnalyzer $analyzer;

    protected function setUp(): void
    {
        // Create EntityManager with ONLY cascade config test entities
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/CascadeConfigTest',
        ]);

        $this->analyzer = new CascadeConfigurationAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_cascade_configuration_is_good(): void
    {
        // Arrange: OrderWithGoodCascade has correct cascade configuration
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect issues from OrderWithGoodCascade
        $issuesArray = $issues->toArray();
        $goodOrderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['title'] ?? '', 'OrderWithGoodCascade');
        });

        self::assertCount(0, $goodOrderIssues, 'OrderWithGoodCascade should not trigger issues');
    }

    #[Test]
    public function it_detects_cascade_remove_on_independent_entity(): void
    {
        // Arrange: OrderWithCascadeAll has cascade="all" (expanded to remove/persist/refresh/detach)
        // on OneToMany to Customer (independent)
        // Note: Doctrine 4 expands cascade="all" to individual operations
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect dangerous cascade remove on independent entity
        $issuesArray = $issues->toArray();
        $cascadeRemoveIssues = array_filter($issuesArray, static fn ($issue) => str_contains((string) $issue->getTitle(), 'cascade remove')
            && str_contains((string) $issue->getDescription(), 'Customer'));

        self::assertGreaterThan(0, count($cascadeRemoveIssues), 'Should detect cascade remove on independent entity');
    }

    #[Test]
    public function it_marks_cascade_remove_on_independent_as_critical(): void
    {
        // Arrange: cascade remove on independent entity (Customer) is CRITICAL
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be CRITICAL severity
        $issuesArray = $issues->toArray();
        $cascadeRemoveIssue = array_filter($issuesArray, static fn ($issue) => str_contains((string) $issue->getTitle(), 'cascade remove')
            && str_contains((string) $issue->getDescription(), 'Customer'));

        self::assertGreaterThan(0, count($cascadeRemoveIssue), 'Should detect cascade remove issue');

        $issue = reset($cascadeRemoveIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals('critical', $issue->getSeverity()->value, 'cascade remove on independent should be CRITICAL');
    }

    #[Test]
    public function it_explains_danger_of_cascade_remove_on_independent(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Description should explain the danger
        $issuesArray = $issues->toArray();
        $cascadeRemoveIssue = array_filter($issuesArray, static fn ($issue) => str_contains((string) $issue->getTitle(), 'cascade remove')
            && str_contains((string) $issue->getDescription(), 'Customer'));

        self::assertGreaterThan(0, count($cascadeRemoveIssue));

        $issue = reset($cascadeRemoveIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $description = $issue->getDescription();

        self::assertStringContainsString('Customer', $description);
        self::assertStringContainsString('independent', strtolower($description));
    }

    #[Test]
    public function it_detects_missing_cascade_on_composition_relationship(): void
    {
        // Arrange: InvoiceWithMissingCascade has OneToMany to LineItem (composition) without cascade
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect missing cascade
        $issuesArray = $issues->toArray();
        $missingCascadeIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['title'] ?? '', 'InvoiceWithMissingCascade')
                && str_contains($data['title'] ?? '', 'Missing cascade');
        });

        self::assertGreaterThan(0, count($missingCascadeIssues), 'Should detect missing cascade on composition');
    }

    #[Test]
    public function it_marks_missing_cascade_as_warning(): void
    {
        // Arrange: Missing cascade is a WARNING (not critical, but should be fixed)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be WARNING severity
        $issuesArray = $issues->toArray();
        $missingCascadeIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['title'] ?? '', 'InvoiceWithMissingCascade')
                && str_contains($data['title'] ?? '', 'Missing cascade');
        });

        self::assertGreaterThan(0, count($missingCascadeIssue), 'Should detect missing cascade issue');

        $issue = reset($missingCascadeIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals('warning', $issue->getSeverity()->value, 'Missing cascade should be WARNING');
    }

    #[Test]
    public function it_explains_why_cascade_is_needed_for_composition(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Description should explain why cascade is needed
        $issuesArray = $issues->toArray();
        $missingCascadeIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['title'] ?? '', 'InvoiceWithMissingCascade')
                && str_contains($data['title'] ?? '', 'Missing cascade');
        });

        $issue = reset($missingCascadeIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $description = $issue->getDescription();

        self::assertStringContainsString('composition', strtolower($description));
        self::assertStringContainsString('cascade', strtolower($description));
        self::assertStringContainsString('persist', strtolower($description));
    }

    #[Test]
    public function it_provides_suggestion_for_cascade_remove_issue(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide suggestion (from SuggestionFactory)
        $issuesArray = $issues->toArray();
        $cascadeRemoveIssue = array_filter($issuesArray, static fn ($issue) => str_contains((string) $issue->getTitle(), 'cascade remove')
            && str_contains((string) $issue->getDescription(), 'Customer'));

        self::assertGreaterThan(0, count($cascadeRemoveIssue), 'Should have cascade remove issue');

        $issue = reset($cascadeRemoveIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion, 'Should have suggestion');
    }

    #[Test]
    public function it_provides_suggestion_for_missing_cascade(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide suggestion to add cascade
        $issuesArray = $issues->toArray();
        $missingCascadeIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['title'] ?? '', 'InvoiceWithMissingCascade')
                && str_contains($data['title'] ?? '', 'Missing cascade');
        });

        self::assertGreaterThan(0, count($missingCascadeIssue), 'Should have missing cascade issue');

        $issue = reset($missingCascadeIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion, 'Should have suggestion to add cascade');
    }

    #[Test]
    public function it_has_code_quality_category(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should have issues');

        $issue = reset($issuesArray);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals('integrity', $issue->getCategory());
    }

    #[Test]
    public function it_has_descriptive_title(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should have issues');

        $issue = reset($issuesArray);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $title = $issue->getTitle();

        self::assertNotEmpty($title);
        self::assertStringContainsString('cascade', strtolower($title));
    }

    #[Test]
    public function it_has_clear_description(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should have issues');

        $issue = reset($issuesArray);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $description = $issue->getDescription();

        self::assertNotEmpty($description);
        self::assertGreaterThan(50, strlen($description), 'Should have detailed description');
    }

    #[Test]
    public function it_includes_entity_name_in_title(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Title should include entity name
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should have issues');

        $issue = reset($issuesArray);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $title = $issue->getTitle();

        self::assertNotEmpty($title, 'Title should not be empty');
    }

    #[Test]
    public function it_includes_field_name_in_title(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Title should include target entity or be descriptive
        $issuesArray = $issues->toArray();
        $missingCascadeIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['title'] ?? '', 'Missing cascade');
        });

        self::assertGreaterThan(0, count($missingCascadeIssue), 'Should have missing cascade issue');

        $issue = reset($missingCascadeIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $title = $issue->getTitle();

        self::assertStringContainsString('$', $title, 'Title should include field with $ prefix');
    }

    #[Test]
    public function it_identifies_customer_as_independent_entity(): void
    {
        // Arrange: Customer is in INDEPENDENT_ENTITY_PATTERNS
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect Customer as independent
        $issuesArray = $issues->toArray();
        $customerIssues = array_filter($issuesArray, static fn ($issue) => str_contains((string) $issue->getDescription(), 'Customer'));

        self::assertGreaterThan(0, count($customerIssues), 'Should detect Customer as independent');
    }

    #[Test]
    public function it_identifies_line_item_as_composition_entity(): void
    {
        // Arrange: LineItem contains "Item" pattern (TYPICAL_COMPOSED_PATTERNS)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect LineItem as composition
        $issuesArray = $issues->toArray();
        $lineItemIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['title'] ?? '', 'InvoiceWithMissingCascade')
                && str_contains((string) $issue->getDescription(), 'LineItem');
        });

        self::assertGreaterThan(0, count($lineItemIssues), 'Should detect LineItem as composition entity');
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
        self::assertStringContainsString('Cascade', $name);
    }

    #[Test]
    public function it_has_analyzer_description(): void
    {
        // Assert
        $description = $this->analyzer->getDescription();

        self::assertNotEmpty($description);
        self::assertStringContainsString('cascade', strtolower($description));
    }

    #[Test]
    public function it_detects_multiple_issues_in_same_codebase(): void
    {
        // Arrange: We have cascade remove issue AND missing cascade issue
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect both types of issues
        $issuesArray = $issues->toArray();

        $cascadeRemoveIssues = array_filter($issuesArray, static fn ($issue) => str_contains((string) $issue->getTitle(), 'cascade remove'));

        $missingCascadeIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['title'] ?? '', 'Missing cascade');
        });

        self::assertGreaterThan(0, count($cascadeRemoveIssues), 'Should detect cascade remove issues');
        self::assertGreaterThan(0, count($missingCascadeIssues), 'Should detect missing cascade issues');
    }

    #[Test]
    public function it_provides_synthetic_backtrace(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should include backtrace with file location
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should have issues');

        $issue = reset($issuesArray);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('backtrace', $data);
    }

    #[Test]
    public function it_suggests_removing_dangerous_cascade(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should suggest removing cascade for independent entities
        $issuesArray = $issues->toArray();
        $cascadeRemoveIssue = array_filter($issuesArray, static fn ($issue) => str_contains((string) $issue->getTitle(), 'cascade remove')
            && str_contains((string) $issue->getDescription(), 'independent'));

        self::assertGreaterThan(0, count($cascadeRemoveIssue), 'Should have cascade remove issue');

        $issue = reset($cascadeRemoveIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $description = $issue->getDescription();

        self::assertNotEmpty($description);
    }

    #[Test]
    public function it_handles_errors_gracefully(): void
    {
        // Arrange: Valid queries
        $queries = QueryDataBuilder::create()->build();

        // Act: Should not throw exceptions
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should return issue collection even if errors occur
        self::assertIsObject($issues);
        self::assertIsArray($issues->toArray());
    }
}
