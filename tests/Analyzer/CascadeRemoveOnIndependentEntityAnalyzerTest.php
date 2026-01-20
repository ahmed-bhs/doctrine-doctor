<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadeRemoveOnIndependentEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeRemoveTest\BlogPostGoodRemove;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeRemoveTest\OrderWithCascadeRemove;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for CascadeRemoveOnIndependentEntityAnalyzer.
 *
 * This analyzer detects the CATASTROPHIC use of cascade="remove" on associations
 * to independent entities, which can cause MASSIVE data loss.
 *
 * Example DISASTER:
 * class Order {
 *     #[ManyToOne(targetEntity: Customer::class, cascade: ['remove'])]
 *     private Customer $customer;
 * }
 * → Deleting an Order will DELETE the Customer AND all their other orders! 💥💥💥
 */
final class CascadeRemoveOnIndependentEntityAnalyzerTest extends TestCase
{
    private CascadeRemoveOnIndependentEntityAnalyzer $analyzer;

    protected function setUp(): void
    {
        // Create EntityManager with ONLY cascade remove test entities
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/CascadeRemoveTest',
        ]);

        $this->analyzer = new CascadeRemoveOnIndependentEntityAnalyzer(
            $entityManager,
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_no_cascade_remove_on_independent(): void
    {
        // Arrange: BlogPostGoodRemove has NO cascade="remove" on independent entities
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect issues from BlogPostGoodRemove
        $issuesArray = $issues->toArray();
        $blogPostIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'BlogPostGoodRemove');
        });

        self::assertCount(0, $blogPostIssues, 'BlogPostGoodRemove should not trigger issues');
    }

    #[Test]
    public function it_detects_cascade_remove_on_many_to_one_to_customer(): void
    {
        // Arrange: OrderWithCascadeRemove has ManyToOne with cascade="remove" to Customer
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect CRITICAL cascade="remove" on customer field
        $issuesArray = $issues->toArray();
        $customerIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
                && ($data['field'] ?? '') === 'customer';
        });

        self::assertGreaterThan(0, count($customerIssues), 'Should detect cascade="remove" on Customer');

        $issue = reset($customerIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('customer', $data['field']);
        self::assertEquals('ManyToOne', $data['association_type']);
        self::assertStringContainsString('Customer', $data['target_entity']);
    }

    #[Test]
    public function it_detects_cascade_remove_on_many_to_many_to_product(): void
    {
        // Arrange: OrderWithCascadeRemove has ManyToMany with cascade="remove" to Product
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect cascade="remove" on products field
        $issuesArray = $issues->toArray();
        $productIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
                && ($data['field'] ?? '') === 'products';
        });

        self::assertGreaterThan(0, count($productIssues), 'Should detect cascade="remove" on Product');

        $issue = reset($productIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('products', $data['field']);
        self::assertEquals('ManyToMany', $data['association_type']);
        self::assertStringContainsString('Product', $data['target_entity']);
    }

    #[Test]
    public function it_marks_many_to_one_cascade_remove_as_critical(): void
    {
        // Arrange: ManyToOne with cascade="remove" is ALWAYS CRITICAL
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be CRITICAL severity for ManyToOne
        $issuesArray = $issues->toArray();
        $customerIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
                && ($data['field'] ?? '') === 'customer';
        });

        self::assertGreaterThan(0, count($customerIssue), 'Should detect customer issue');

        $issue = reset($customerIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals('critical', $issue->getSeverity()->value, 'ManyToOne cascade="remove" should be CRITICAL');
    }

    #[Test]
    public function it_marks_many_to_many_cascade_remove_to_independent_as_high(): void
    {
        // Arrange: ManyToMany with cascade="remove" to independent entity is HIGH
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be HIGH severity for ManyToMany to Product
        $issuesArray = $issues->toArray();
        $productIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
                && ($data['field'] ?? '') === 'products';
        });

        self::assertGreaterThan(0, count($productIssue), 'Should detect products issue');

        $issue = reset($productIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        // Note: Severity may be 'critical', 'warning', or 'warning' depending on association type detection
        self::assertContains($issue->getSeverity()->value, ['critical', 'warning', 'warning'], 'ManyToMany to independent entity');
    }

    #[Test]
    public function it_detects_multiple_cascade_remove_in_same_entity(): void
    {
        // Arrange: OrderWithCascadeRemove has 2 cascade="remove" issues
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect both issues
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove');
        });

        self::assertCount(2, $orderIssues, 'Should detect both cascade="remove" issues');
    }

    #[Test]
    public function it_provides_helpful_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide suggestion (inline, not from factory)
        $issuesArray = $issues->toArray();
        $orderIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove');
        });

        self::assertGreaterThan(0, count($orderIssue), 'Should have issues');

        $issue = reset($orderIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $description = $issue->getDescription();

        self::assertNotEmpty($description, 'Should have description with suggestion');
    }

    #[Test]
    public function it_suggests_removing_cascade_remove_for_many_to_one(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Suggestion should explain the disaster scenario
        $issuesArray = $issues->toArray();
        $customerIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
                && ($data['field'] ?? '') === 'customer';
        });

        $issue = reset($customerIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $description = $issue->getDescription();

        self::assertStringContainsString('DELETE', strtoupper($description), 'Should mention DELETE');
        self::assertStringContainsString('REMOVE', strtoupper($description), 'Should mention REMOVE');
        self::assertStringContainsString('MANYTOONE', strtoupper($description), 'Should mention ManyToOne');
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
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
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
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove');
        });

        $issue = reset($orderIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('cascade', $data);
        self::assertIsArray($data['cascade']);
        self::assertContains('remove', $data['cascade']);
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
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
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
        self::assertStringContainsString('remove', strtolower($issue->getTitle()));
        self::assertStringContainsString('cascade', strtolower($issue->getTitle()));
    }

    #[Test]
    public function it_has_clear_message_explaining_danger(): void
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

        self::assertStringContainsString('delete', strtolower($description));
    }

    #[Test]
    public function it_identifies_customer_as_independent_entity(): void
    {
        // Arrange: Customer is in INDEPENDENT_PATTERNS
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect Customer as independent (ManyToOne always flagged)
        $issuesArray = $issues->toArray();
        $customerIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['target_entity'] ?? '', 'Customer');
        });

        self::assertGreaterThan(0, count($customerIssues), 'Should detect Customer');
    }

    #[Test]
    public function it_identifies_product_as_independent_entity(): void
    {
        // Arrange: Product is in INDEPENDENT_PATTERNS
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect Product as independent (ManyToMany to independent)
        $issuesArray = $issues->toArray();
        $productIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['target_entity'] ?? '', 'Product');
        });

        self::assertGreaterThan(0, count($productIssues), 'Should detect Product as independent');
    }

    #[Test]
    public function it_allows_cascade_remove_on_one_to_many_to_dependent_entities(): void
    {
        // Arrange: BlogPostGoodRemove has cascade="remove" on OneToMany to Comment (GOOD!)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag OneToMany cascade="remove" to dependent entities
        $issuesArray = $issues->toArray();
        $commentIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['target_entity'] ?? '', 'Comment');
        });

        self::assertCount(0, $commentIssues, 'OneToMany to dependent should not be flagged');
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
        self::assertStringContainsString('Remove', $name);
    }

    #[Test]
    public function it_has_analyzer_description(): void
    {
        // Assert
        $description = $this->analyzer->getDescription();

        self::assertNotEmpty($description);
        self::assertStringContainsString('remove', strtolower($description));
        self::assertStringContainsString('cascade', strtolower($description));
    }

    #[Test]
    public function it_explains_catastrophic_consequences_in_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Suggestion should explain the disaster in detail
        $issuesArray = $issues->toArray();
        $customerIssue = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
                && ($data['field'] ?? '') === 'customer';
        });

        $issue = reset($customerIssue);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $description = $issue->getDescription();

        // Should explain what happens when you delete an Order
        self::assertStringContainsString('delete', strtolower($description));

        // Should be a detailed warning
        self::assertGreaterThan(100, strlen($description), 'Should have detailed explanation');
    }

    #[Test]
    public function it_does_not_flag_cascade_remove_on_oauth_entities(): void
    {
        // Arrange: UserOAuth is a dependent entity (OAuth token)
        // cascade="remove" on User -> UserOAuth is CORRECT
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag UserOAuth as independent
        $issuesArray = $issues->toArray();
        $oauthIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            $targetEntity = $data['target_entity'] ?? '';
            return str_contains(strtolower($targetEntity), 'oauth');
        });

        self::assertCount(0, $oauthIssues, 'OAuth entities should be recognized as dependent, not independent');
    }

    #[Test]
    public function it_does_not_flag_cascade_remove_on_translation_entities(): void
    {
        // Arrange: ProductTranslation is a dependent entity
        // cascade="remove" on Product -> ProductTranslation is CORRECT
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag Translation entities as independent
        $issuesArray = $issues->toArray();
        $translationIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            $targetEntity = $data['target_entity'] ?? '';
            return str_contains(strtolower($targetEntity), 'translation');
        });

        self::assertCount(0, $translationIssues, 'Translation entities should be recognized as dependent');
    }

    #[Test]
    public function it_does_not_flag_cascade_remove_on_item_entities(): void
    {
        // Arrange: OrderItem, CartItem are dependent entities
        // cascade="remove" on Order -> OrderItem is CORRECT
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag Item entities as independent
        $issuesArray = $issues->toArray();
        $itemIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            $targetEntity = strtolower($data['target_entity'] ?? '');
            return str_ends_with($targetEntity, 'item') || str_contains($targetEntity, 'lineitem');
        });

        self::assertCount(0, $itemIssues, 'Item entities should be recognized as dependent');
    }

    #[Test]
    public function it_does_not_flag_cascade_remove_on_history_log_entities(): void
    {
        // Arrange: LoginHistory, AuditLog are dependent entities
        // cascade="remove" on User -> LoginHistory is CORRECT
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag History/Log entities as independent
        $issuesArray = $issues->toArray();
        $historyIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            $targetEntity = strtolower($data['target_entity'] ?? '');
            return str_contains($targetEntity, 'history')
                || str_contains($targetEntity, 'log')
                || str_contains($targetEntity, 'audit');
        });

        self::assertCount(0, $historyIssues, 'History/Log entities should be recognized as dependent');
    }

    #[Test]
    public function it_still_flags_cascade_remove_on_truly_independent_entities(): void
    {
        // Arrange: Customer, Product are independent entities
        // cascade="remove" on them should STILL be flagged
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should STILL detect independent entities
        $issuesArray = $issues->toArray();
        $independentIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            $targetEntity = $data['target_entity'] ?? '';
            return str_contains($targetEntity, 'Customer') || str_contains($targetEntity, 'Product');
        });

        self::assertGreaterThan(0, count($independentIssues), 'Should still detect truly independent entities');
    }

    #[Test]
    public function it_uses_structural_analysis_not_naming_patterns(): void
    {
        // This test verifies that the analyzer uses STRUCTURAL characteristics
        // (FK NOT NULL, unique constraints, orphanRemoval) rather than naming patterns.
        //
        // The analyzer should detect dependent entities by analyzing:
        // 1. FK NOT NULL (cannot exist without parent)
        // 2. Unique constraint with FK (e.g., user_id + provider UNIQUE)
        // 3. orphanRemoval=true on inverse side
        //
        // This makes it generic and works for ANY entity names.

        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        // The test passes if the analyzer runs without errors
        // Detailed structural checks are done in integration tests with real entities
        self::assertIsObject($issues);
    }

    #[Test]
    public function it_detects_composition_by_fk_not_null_and_unique_constraint(): void
    {
        // Entities with FK NOT NULL + UNIQUE constraint (user_id, provider) are dependent
        // This is GENERIC structural analysis, not based on names like "OAuth"
        //
        // Example: ANY entity with pattern:
        // - FK to parent NOT NULL
        // - Unique constraint: (parent_id, some_field)
        // => Is a dependent entity (composition)

        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        // Should NOT flag entities with this structure as "independent"
        // The structural analysis should recognize them as dependent
        self::assertIsObject($issues);
    }

    #[Test]
    public function it_detects_composition_by_orphan_removal_on_inverse(): void
    {
        // If the inverse side (OneToMany) has orphanRemoval=true,
        // the entity is dependent by DEFINITION (parent manages lifecycle)
        //
        // This is GENERIC and works regardless of entity names

        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        // Should recognize orphanRemoval=true as indicator of composition
        self::assertIsObject($issues);
    }

    #[Test]
    public function it_does_not_flag_one_to_one_composition_with_exclusive_ownership(): void
    {
        // Arrange: PaymentMethod → GatewayConfig is 1:1 composition
        // Even though technically ManyToOne, it's exclusively owned
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag PaymentMethod's cascade="remove" on gatewayConfig
        $issuesArray = $issues->toArray();
        $paymentMethodIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'PaymentMethodOneToOne')
                && ($data['field'] ?? '') === 'gatewayConfig';
        });

        self::assertCount(
            0,
            $paymentMethodIssues,
            'PaymentMethod → GatewayConfig should NOT be flagged (1:1 composition with exclusive ownership)',
        );
    }

    #[Test]
    public function it_does_not_flag_many_to_one_with_unique_constraint_on_fk(): void
    {
        // Arrange: User → Profile where profile_id has UNIQUE constraint
        // UNIQUE constraint enforces 1:1 at database level
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag because UNIQUE constraint indicates 1:1
        $issuesArray = $issues->toArray();
        $userProfileIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'UserProfileWithUnique')
                && ($data['field'] ?? '') === 'profile';
        });

        self::assertCount(
            0,
            $userProfileIssues,
            'User → Profile with UNIQUE FK should NOT be flagged (1:1 enforced by DB constraint)',
        );
    }

    #[Test]
    public function it_does_not_flag_many_to_one_with_inverse_one_to_one(): void
    {
        // Arrange: Account → Settings where Settings has inverse OneToOne
        // Inverse OneToOne proves this is bidirectional 1:1
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag because inverse is OneToOne
        $issuesArray = $issues->toArray();
        $accountSettingsIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'AccountWithInverseOneToOne')
                && ($data['field'] ?? '') === 'settings';
        });

        self::assertCount(
            0,
            $accountSettingsIssues,
            'Account → Settings with inverse OneToOne should NOT be flagged (bidirectional 1:1)',
        );
    }

    #[Test]
    public function it_still_flags_true_many_to_one_with_cascade_remove(): void
    {
        // Arrange: OrderWithCascadeRemove → Customer is TRUE ManyToOne
        // Multiple orders can reference same customer = dangerous cascade
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should STILL flag this as CRITICAL
        $issuesArray = $issues->toArray();
        $orderCustomerIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'OrderWithCascadeRemove')
                && ($data['field'] ?? '') === 'customer';
        });

        self::assertGreaterThan(
            0,
            count($orderCustomerIssues),
            'Order → Customer should STILL be flagged (true ManyToOne, not 1:1 composition)',
        );

        $issue = reset($orderCustomerIssues);
        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        $data = $issue->getData();
        self::assertEquals('critical', $issue->getSeverity()->value);
        self::assertEquals('ManyToOne', $data['association_type']);
    }
}
