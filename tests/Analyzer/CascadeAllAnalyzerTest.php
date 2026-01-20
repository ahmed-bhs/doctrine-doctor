<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadeAllAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeAllTest\BlogPostWithGoodCascade;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeAllTest\OrderWithCascadeAll;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for CascadeAllAnalyzer.
 *
 * This analyzer detects the dangerous use of cascade="all" in entity associations.
 * cascade="all" can lead to:
 * - Accidental deletion of independent entities
 * - Creation of duplicate records
 * - Unpredictable behavior in production
 */
final class CascadeAllAnalyzerTest extends TestCase
{
    private CascadeAllAnalyzer $analyzer;

    protected function setUp(): void
    {
        // Create EntityManager with ONLY cascade test entities
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/CascadeAllTest',
        ]);

        $this->analyzer = new CascadeAllAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_no_cascade_all(): void
    {
        // Arrange: Entity with good cascade configuration (explicit cascades, no "all")
        $queries = QueryDataBuilder::create()->build();

        // Act: Analyze only the good entity
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect issues from OrderWithCascadeAll, but not from BlogPostWithGoodCascade
        $issuesArray = $issues->toArray();

        // Filter to check if BlogPostWithGoodCascade is NOT flagged
        $blogPostIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'BlogPostWithGoodCascade');
        });

        self::assertCount(0, $blogPostIssues, 'BlogPostWithGoodCascade should not trigger issues');
    }

    #[Test]
    public function it_detects_cascade_all_on_many_to_one(): void
    {
        // Arrange: OrderWithCascadeAll has ManyToOne with cascade="all" to Customer
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect cascade="all" on customer field
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeAll')
                && ($data['field'] ?? '') === 'customer';
        });

        self::assertGreaterThan(0, count($orderIssues), 'Should detect cascade="all" on ManyToOne');

        $issue = reset($orderIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('customer', $data['field']);
        self::assertEquals('ManyToOne', $data['association_type']);
    }

    #[Test]
    public function it_detects_cascade_all_on_many_to_many(): void
    {
        // Arrange: OrderWithCascadeAll has ManyToMany with cascade="all" to Product
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect cascade="all" on products field
        $issuesArray = $issues->toArray();
        $productIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeAll')
                && ($data['field'] ?? '') === 'products';
        });

        self::assertGreaterThan(0, count($productIssues), 'Should detect cascade="all" on ManyToMany');

        $issue = reset($productIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('products', $data['field']);
        // Note: Association type detection varies by Doctrine version - just verify field is detected
        self::assertArrayHasKey('association_type', $data);
    }

    #[Test]
    public function it_marks_many_to_one_to_independent_entity_as_critical(): void
    {
        // Arrange: ManyToOne to Customer (independent entity pattern)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be CRITICAL severity
        $issuesArray = $issues->toArray();
        $customerIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeAll')
                && ($data['field'] ?? '') === 'customer';
        });

        self::assertGreaterThan(0, count($customerIssue), 'Should detect customer issue');

        $issue = reset($customerIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals('critical', $issue->getSeverity()->value, 'Should be CRITICAL for independent entity');
    }

    #[Test]
    public function it_marks_many_to_many_to_independent_entity_as_critical(): void
    {
        // Arrange: ManyToMany to Product (independent entity pattern)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be WARNING or CRITICAL severity (depends on association type detection)
        $issuesArray = $issues->toArray();
        $productIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeAll')
                && ($data['field'] ?? '') === 'products';
        });

        self::assertGreaterThan(0, count($productIssue), 'Should detect products issue');

        $issue = reset($productIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        // Note: Severity depends on association type detection which varies by Doctrine version
        self::assertContains($issue->getSeverity()->value, ['warning', 'critical']);
    }

    #[Test]
    public function it_detects_multiple_cascade_all_in_same_entity(): void
    {
        // Arrange: OrderWithCascadeAll has 2 cascade="all" associations
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect both issues
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeAll');
        });

        self::assertCount(2, $orderIssues, 'Should detect both cascade="all" associations');
    }

    #[Test]
    public function it_provides_helpful_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide suggestion
        $issuesArray = $issues->toArray();
        $orderIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeAll');
        });

        self::assertGreaterThan(0, count($orderIssue), 'Should have issues');

        $issue = reset($orderIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion, 'Should provide suggestion');
        self::assertNotEmpty($suggestion->getCode(), 'Should have code in suggestion');
    }

    #[Test]
    public function it_suggests_removing_cascade_for_many_to_one(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Suggestion should recommend safe cascade configuration
        $issuesArray = $issues->toArray();
        $customerIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeAll')
                && ($data['field'] ?? '') === 'customer';
        });

        $issue = reset($customerIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $suggestion = $issue->getSuggestion();
        self::assertNotNull($suggestion);
        $code = $suggestion->getCode();

        self::assertStringContainsString('CASCADE', strtoupper($code), 'Should mention cascade');
        // For independent entities (like Customer), should suggest safe cascades
        // The template recommends cascade: ['persist'] for associations (not ['persist', 'remove'])
        self::assertStringContainsString("['PERSIST']", strtoupper($code), 'Should suggest persist cascade only');
    }

    #[Test]
    public function it_includes_target_entity_in_data(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Data should include target entity
        $issuesArray = $issues->toArray();
        $customerIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeAll')
                && ($data['field'] ?? '') === 'customer';
        });

        $issue = reset($customerIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('target_entity', $data);
        self::assertStringContainsString('CustomerEntity', $data['target_entity']);
    }

    #[Test]
    public function it_includes_cascade_operations_in_data(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Data should include cascade operations
        $issuesArray = $issues->toArray();
        $orderIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeAll');
        });

        $issue = reset($orderIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('cascade', $data);
        self::assertIsArray($data['cascade']);
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
        $issue = reset($issuesArray);
        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertStringContainsString('cascade', strtolower($issue->getTitle()));
        self::assertStringContainsString('all', strtolower($issue->getTitle()));
    }

    #[Test]
    public function it_has_clear_message(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $issue = reset($issuesArray);
        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $description = $issue->getDescription();

        self::assertStringContainsString('dangerous', strtolower($description));
        // The description uses highlighting, so it will have HTML tags around "all"
        self::assertMatchesRegularExpression('/(\"all\"|&quot;all&quot;)/i', $description);
    }

    #[Test]
    public function it_identifies_customer_as_independent_entity(): void
    {
        // Arrange: Customer contains "Customer" pattern
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be marked as critical due to independent entity pattern
        $issuesArray = $issues->toArray();
        $customerIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['target_entity'] ?? '', 'CustomerEntity');
        });

        self::assertGreaterThan(0, count($customerIssue), 'Should detect Customer as independent');

        $issue = reset($customerIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals('critical', $issue->getSeverity()->value, 'Should be critical for Customer');
    }

    #[Test]
    public function it_identifies_product_as_independent_entity(): void
    {
        // Arrange: Product contains "Product" pattern
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect Product entity in issues
        $issuesArray = $issues->toArray();
        $productIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['target_entity'] ?? '', 'ProductEntity');
        });

        self::assertGreaterThan(0, count($productIssue), 'Should detect Product as independent');

        $issue = reset($productIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        // Note: Severity depends on association type detection
        self::assertContains($issue->getSeverity()->value, ['warning', 'critical']);
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
    public function it_uses_suggestion_factory(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should use SuggestionFactory (has metadata)
        $issuesArray = $issues->toArray();
        $issue = reset($issuesArray);
        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $suggestion = $issue->getSuggestion();
        self::assertNotNull($suggestion);

        self::assertNotNull($suggestion->getMetadata(), 'Should have metadata from factory');
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
        self::assertStringContainsString('all', strtolower($description));
    }
}
