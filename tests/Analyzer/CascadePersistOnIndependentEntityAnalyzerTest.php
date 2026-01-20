<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadePersistOnIndependentEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadePersistTest\BlogPostGoodCascade;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadePersistTest\OrderWithCascadePersist;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for CascadePersistOnIndependentEntityAnalyzer.
 *
 * This analyzer detects cascade="persist" on associations to independent entities,
 * which can lead to duplicate records instead of loading existing entities.
 *
 * Example CRITICAL issue:
 * class Order {
 *     #[ManyToOne(targetEntity: Customer::class, cascade: ['persist'])]
 *     private Customer $customer;
 * }
 * → Creates DUPLICATE customers instead of loading existing ones! 💥
 */
final class CascadePersistOnIndependentEntityAnalyzerTest extends TestCase
{
    private CascadePersistOnIndependentEntityAnalyzer $analyzer;

    protected function setUp(): void
    {
        // Create EntityManager with ONLY cascade persist test entities
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/CascadePersistTest',
        ]);

        $this->analyzer = new CascadePersistOnIndependentEntityAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_no_cascade_persist_on_independent(): void
    {
        // Arrange: BlogPostGoodCascade has NO cascade persist on independent entities
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect issues from BlogPostGoodCascade
        $issuesArray = $issues->toArray();
        $blogPostIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'BlogPostGoodCascade');
        });

        self::assertCount(0, $blogPostIssues, 'BlogPostGoodCascade should not trigger issues');
    }

    #[Test]
    public function it_detects_cascade_persist_on_many_to_one_to_customer(): void
    {
        // Arrange: OrderWithCascadePersist has ManyToOne with cascade="persist" to Customer
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect cascade="persist" on customer field
        $issuesArray = $issues->toArray();
        $customerIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadePersist')
                && ($data['field'] ?? '') === 'customer';
        });

        self::assertGreaterThan(0, count($customerIssues), 'Should detect cascade="persist" on Customer');

        $issue = reset($customerIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('customer', $data['field']);
        self::assertEquals('ManyToOne', $data['association_type']);
        self::assertStringContainsString('Customer', $data['target_entity']);
    }

    #[Test]
    public function it_detects_cascade_persist_on_many_to_many_to_product(): void
    {
        // Arrange: OrderWithCascadePersist has ManyToMany with cascade="persist" to Product
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect cascade="persist" on products field
        $issuesArray = $issues->toArray();
        $productIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadePersist')
                && ($data['field'] ?? '') === 'products';
        });

        self::assertGreaterThan(0, count($productIssues), 'Should detect cascade="persist" on Product');

        $issue = reset($productIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('products', $data['field']);
        self::assertStringContainsString('Product', $data['target_entity']);
    }

    #[Test]
    public function it_marks_customer_as_critical_independent_entity(): void
    {
        // Arrange: Customer is in CRITICAL_INDEPENDENT_PATTERNS
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be CRITICAL severity for Customer
        $issuesArray = $issues->toArray();
        $customerIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadePersist')
                && ($data['field'] ?? '') === 'customer';
        });

        self::assertGreaterThan(0, count($customerIssue), 'Should detect customer issue');

        $issue = reset($customerIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals('critical', $issue->getSeverity()->value, 'Customer should be CRITICAL');
    }

    #[Test]
    public function it_marks_product_as_independent_entity(): void
    {
        // Arrange: Product is in INDEPENDENT_PATTERNS
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect Product as independent
        $issuesArray = $issues->toArray();
        $productIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadePersist')
                && ($data['field'] ?? '') === 'products';
        });

        self::assertGreaterThan(0, count($productIssue), 'Should detect Product as independent');

        $issue = reset($productIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        // Product severity depends on reference count (info/warning/high)
        self::assertContains($issue->getSeverity()->value, ['info', 'warning', 'warning', 'critical']);
    }

    #[Test]
    public function it_detects_multiple_cascade_persist_in_same_entity(): void
    {
        // Arrange: OrderWithCascadePersist has 2 cascade="persist" to independent entities
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect both issues
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadePersist');
        });

        self::assertCount(2, $orderIssues, 'Should detect both cascade="persist" issues');
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
            return str_contains($data['entity'] ?? '', 'OrderWithCascadePersist');
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
    public function it_suggests_removing_cascade_persist(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Suggestion should recommend removing cascade persist
        $issuesArray = $issues->toArray();
        $customerIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadePersist')
                && ($data['field'] ?? '') === 'customer';
        });

        $issue = reset($customerIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $suggestion = $issue->getSuggestion();
        self::assertNotNull($suggestion);
        $code = $suggestion->getCode();

        self::assertStringContainsString('CASCADE', strtoupper($code), 'Should mention cascade');
        // Should suggest removing cascade persist for independent entities
        self::assertStringNotContainsString("CASCADE: ['PERSIST']", strtoupper($code), 'Should suggest NO cascade');
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
            return str_contains($data['entity'] ?? '', 'OrderWithCascadePersist')
                && ($data['field'] ?? '') === 'customer';
        });

        $issue = reset($customerIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('target_entity', $data);
        self::assertStringContainsString('Customer', $data['target_entity']);
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
            return str_contains($data['entity'] ?? '', 'OrderWithCascadePersist');
        });

        $issue = reset($orderIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('cascade', $data);
        self::assertIsArray($data['cascade']);
        self::assertContains('persist', $data['cascade']);
    }

    #[Test]
    public function it_includes_reference_count_in_data(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Data should include reference count
        $issuesArray = $issues->toArray();
        $customerIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadePersist')
                && ($data['field'] ?? '') === 'customer';
        });

        $issue = reset($customerIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('reference_count', $data);
        self::assertIsInt($data['reference_count']);
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
        self::assertStringContainsString('persist', strtolower($issue->getTitle()));
        self::assertStringContainsString('independent', strtolower($issue->getTitle()));
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

        self::assertStringContainsString('independent', strtolower($description));
        self::assertStringContainsString('duplicate', strtolower($description));
    }

    #[Test]
    public function it_identifies_author_as_independent_entity(): void
    {
        // Arrange: Author is in INDEPENDENT_PATTERNS
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag Author from BlogPostGoodCascade (no cascade persist)
        $issuesArray = $issues->toArray();
        $authorIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['target_entity'] ?? '', 'Author');
        });

        self::assertCount(0, $authorIssues, 'Author should not be flagged (no cascade persist)');
    }

    #[Test]
    public function it_allows_cascade_persist_on_dependent_entities(): void
    {
        // Arrange: BlogPostGoodCascade has cascade="persist" on Comment (DEPENDENT entity)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag Comment (dependent entity, not independent)
        $issuesArray = $issues->toArray();
        $commentIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['target_entity'] ?? '', 'Comment');
        });

        self::assertCount(0, $commentIssues, 'Comment should not be flagged (dependent entity)');
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
        self::assertStringContainsString('Persist', $name);
    }

    #[Test]
    public function it_has_analyzer_description(): void
    {
        // Assert
        $description = $this->analyzer->getDescription();

        self::assertNotEmpty($description);
        self::assertStringContainsString('persist', strtolower($description));
        self::assertStringContainsString('independent', strtolower($description));
    }

    #[Test]
    public function it_includes_backtrace_in_data(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Data should include backtrace pointing to entity field
        $issuesArray = $issues->toArray();
        $issue = reset($issuesArray);
        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('backtrace', $data);
        if (null !== $data['backtrace']) {
            self::assertIsArray($data['backtrace']);
            self::assertNotEmpty($data['backtrace']);
        }
    }
}
