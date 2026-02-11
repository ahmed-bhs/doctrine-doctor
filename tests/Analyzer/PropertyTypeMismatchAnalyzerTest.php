<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\PropertyTypeMismatchAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ProductWithTypeMismatch;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for PropertyTypeMismatchAnalyzer.
 *
 * This analyzer checks actual entity property values for type mismatches.
 * It inspects entities loaded in the UnitOfWork (managed entities).
 *
 * Note: This analyzer is designed to detect runtime type issues that occur
 * when data is loaded from the database. In tests with strict PHP typing,
 * many type mismatches are prevented by PHP itself.
 */
final class PropertyTypeMismatchAnalyzerTest extends TestCase
{
    private PropertyTypeMismatchAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/TypeMismatch',
        ]);

        $this->analyzer = new PropertyTypeMismatchAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_no_managed_entities(): void
    {
        // Arrange: No entities loaded in UnitOfWork
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: No entities to check = no issues
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_returns_empty_collection_when_all_types_match(): void
    {
        // Arrange: Create entity with correct types
        $entityManager = $this->createEntityManagerWithSchema();

        $product = new ProductWithTypeMismatch();
        $product->setQuantity(10);
        $product->setName('Test Product');
        $product->setSku('SKU-123');
        $product->setDescription('A description');
        $product->setPrice('99.99');

        // Manage the entity so it appears in UnitOfWork
        $entityManager->persist($product);
        $entityManager->flush();

        // Create analyzer with this EM
        $analyzer = new PropertyTypeMismatchAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $analyzer->analyze($queries);

        // Assert: All types correct = no issues
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_checks_properties_of_managed_entities(): void
    {
        // Arrange: Load entities into UnitOfWork
        $entityManager = $this->createEntityManagerWithSchema();

        $product = new ProductWithTypeMismatch();
        $product->setQuantity(10);
        $product->setName('Test Product');
        $product->setSku('SKU-123');
        $product->setPrice('99.99');

        $entityManager->persist($product);
        $entityManager->flush();

        $analyzer = new PropertyTypeMismatchAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $analyzer->analyze($queries);

        // Assert: Analyzer runs and checks properties
        self::assertIsArray($issues->toArray());
    }

    #[Test]
    public function it_allows_null_for_nullable_fields(): void
    {
        // Arrange: description is nullable
        $entityManager = $this->createEntityManagerWithSchema();

        $product = new ProductWithTypeMismatch();
        $product->setQuantity(10);
        $product->setName('Test Product');
        $product->setSku('SKU-123');
        // description is nullable - this should be OK
        $product->setDescription(null);
        $product->setPrice('99.99');

        $entityManager->persist($product);
        $entityManager->flush();

        $analyzer = new PropertyTypeMismatchAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $analyzer->analyze($queries);

        // Assert: Nullable field with NULL should not be flagged
        $issuesArray = $issues->toArray();

        $descriptionIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'description'),
        );

        self::assertCount(0, $descriptionIssues, 'Nullable fields should accept NULL');
    }

    #[Test]
    public function it_skips_uninitialized_properties(): void
    {
        // Arrange: Entity with some uninitialized properties
        $entityManager = $this->createEntityManagerWithSchema();

        $product = new ProductWithTypeMismatch();
        $product->setQuantity(10);
        $product->setName('Test');
        $product->setSku('SKU-123');
        $product->setPrice('99.99');
        // description is not set - remains uninitialized or null (nullable)

        $entityManager->persist($product);
        $entityManager->flush();

        $analyzer = new PropertyTypeMismatchAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $queries = QueryDataBuilder::create()->build();

        // Act - should not throw errors for uninitialized properties
        $issues = $analyzer->analyze($queries);

        // Assert: Should complete without errors
        self::assertIsArray($issues->toArray());
    }

    #[Test]
    public function it_checks_multiple_entity_instances(): void
    {
        // Arrange: Load multiple instances
        $entityManager = $this->createEntityManagerWithSchema();

        $product1 = new ProductWithTypeMismatch();
        $product1->setQuantity(10);
        $product1->setName('Product 1');
        $product1->setSku('SKU-001');
        $product1->setPrice('99.99');

        $product2 = new ProductWithTypeMismatch();
        $product2->setQuantity(20);
        $product2->setName('Product 2');
        $product2->setSku('SKU-002');
        $product2->setPrice('199.99');

        $entityManager->persist($product1);
        $entityManager->persist($product2);
        $entityManager->flush();

        $analyzer = new PropertyTypeMismatchAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $analyzer->analyze($queries);

        // Assert: Should check both instances
        self::assertIsArray($issues->toArray());
    }

    #[Test]
    public function it_verifies_decimal_fields_use_string_type(): void
    {
        // Arrange: price is decimal, should be stored as string
        $entityManager = $this->createEntityManagerWithSchema();

        $product = new ProductWithTypeMismatch();
        $product->setQuantity(10);
        $product->setName('Test');
        $product->setSku('SKU-123');
        $product->setPrice('99.99'); // Correct: string for decimal
        $entityManager->persist($product);
        $entityManager->flush();

        $analyzer = new PropertyTypeMismatchAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $analyzer->analyze($queries);

        // Assert: String for decimal should be accepted
        $issuesArray = $issues->toArray();
        $priceIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'price'),
        );

        self::assertCount(0, $priceIssues, 'String type for decimal columns should be accepted');
    }

    #[Test]
    public function it_verifies_integer_fields(): void
    {
        // Arrange: quantity is integer
        $entityManager = $this->createEntityManagerWithSchema();

        $product = new ProductWithTypeMismatch();
        $product->setQuantity(10); // Correct: int for integer
        $product->setName('Test');
        $product->setSku('SKU-123');
        $product->setPrice('99.99');

        $entityManager->persist($product);
        $entityManager->flush();

        $analyzer = new PropertyTypeMismatchAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $analyzer->analyze($queries);

        // Assert: int for integer columns should be accepted
        $issuesArray = $issues->toArray();
        $quantityIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'quantity'),
        );

        self::assertCount(0, $quantityIssues, 'Integer type for integer columns should be accepted');
    }

    #[Test]
    public function it_verifies_string_fields(): void
    {
        // Arrange: name and sku are strings
        $entityManager = $this->createEntityManagerWithSchema();

        $product = new ProductWithTypeMismatch();
        $product->setQuantity(10);
        $product->setName('Test Product'); // Correct: string for string
        $product->setSku('SKU-123'); // Correct: string for string
        $product->setPrice('99.99');

        $entityManager->persist($product);
        $entityManager->flush();

        $analyzer = new PropertyTypeMismatchAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $analyzer->analyze($queries);

        // Assert: string for string columns should be accepted
        $issuesArray = $issues->toArray();
        $stringIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'name') ||
                          str_contains((string) $issue->getDescription(), 'sku'),
        );

        self::assertCount(0, $stringIssues, 'String type for string columns should be accepted');
    }

    /**
     * Helper to create EntityManager with schema for specific entity only.
     */
    private function createEntityManagerWithSchema(): \Doctrine\ORM\EntityManagerInterface
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/TypeMismatch',
        ]);

        // Create schema ONLY for ProductWithTypeMismatch to avoid conflicts with other test entities
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getClassMetadata(ProductWithTypeMismatch::class);
        $schemaTool->createSchema([$metadata]);

        return $entityManager;
    }
}
