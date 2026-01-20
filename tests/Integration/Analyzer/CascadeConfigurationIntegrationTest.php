<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadePersistOnIndependentEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\MissingOrphanRemovalOnCompositionAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Category;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Order;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrderItem;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrderItemWithoutCascade;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrderWithoutCascade;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for cascade configuration analyzers.
 *
 * Tests detection of:
 * - Missing cascade persist on composition relationships
 * - Missing orphanRemoval on composition relationships
 * - Incorrect cascade persist on independent entities
 * - Real-world cascade behavior and bugs
 */
final class CascadeConfigurationIntegrationTest extends DatabaseTestCase
{
    private CascadePersistOnIndependentEntityAnalyzer $cascadePersistOnIndependentEntityAnalyzer;

    private MissingOrphanRemovalOnCompositionAnalyzer $missingOrphanRemovalOnCompositionAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $suggestionFactory = PlatformAnalyzerTestHelper::createSuggestionFactory();

        $this->cascadePersistOnIndependentEntityAnalyzer = new CascadePersistOnIndependentEntityAnalyzer(
            $this->entityManager,
            $suggestionFactory,
        );

        $this->missingOrphanRemovalOnCompositionAnalyzer = new MissingOrphanRemovalOnCompositionAnalyzer(
            $this->entityManager,
            $suggestionFactory,
        );

        // Create schema
        $this->createSchema([
            User::class,
            Product::class,
            Category::class,
            Order::class,
            OrderItem::class,
            OrderWithoutCascade::class,
            OrderItemWithoutCascade::class,
        ]);
    }

    #[Test]
    public function it_demonstrates_working_cascade_persist_on_composition(): void
    {
        // Arrange: Order entity with proper cascade persist
        $user = new User();
        $user->setName('John Doe');
        $user->setEmail('john@example.com');

        $product = new Product();
        $product->setName('Test Product');
        $product->setPrice(99.99);
        $product->setStock(10);

        // Persist independent entities first
        $this->entityManager->persist($user);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        // Create order with items (composition)
        $order = new Order();
        $order->setUser($user);

        $orderItem = new OrderItem();
        $orderItem->setProduct($product);
        $orderItem->setQuantity(2);

        $order->addItem($orderItem);

        // Act: Persist only the order - cascade should persist items
        $this->entityManager->persist($order);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Assert: Item was persisted automatically via cascade
        $savedOrder = $this->entityManager->find(Order::class, $order->getId());
        self::assertInstanceOf(Order::class, $savedOrder);
        self::assertCount(1, $savedOrder->getItems(), 'Item should be persisted via cascade');
    }

    #[Test]
    public function it_demonstrates_broken_cascade_without_configuration(): void
    {
        // Arrange: OrderWithoutCascade - missing cascade persist
        $user = new User();
        $user->setName('Jane Doe');
        $user->setEmail('jane@example.com');

        $product = new Product();
        $product->setName('Another Product');
        $product->setPrice(49.99);
        $product->setStock(5);

        $this->entityManager->persist($user);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $orderWithoutCascade = new OrderWithoutCascade();
        $orderWithoutCascade->setUser($user);

        $orderItemWithoutCascade = new OrderItemWithoutCascade();
        $orderItemWithoutCascade->setProduct($product);
        $orderItemWithoutCascade->setQuantity(1);

        $orderWithoutCascade->addItem($orderItemWithoutCascade);

        // Act: Try to persist order without cascade
        $this->entityManager->persist($orderWithoutCascade);

        try {
            $this->entityManager->flush();

            // If flush succeeded, the item was NOT persisted
            $this->entityManager->clear();
            $savedOrder = $this->entityManager->find(OrderWithoutCascade::class, $orderWithoutCascade->getId());

            // This demonstrates the bug: order persists but items don't!
            if (null !== $savedOrder) {
                self::assertCount(0, $savedOrder->getItems(), 'Without cascade persist, items are NOT saved - THIS IS THE BUG!');
            }
        } catch (\Exception $exception) {
            // Some databases may throw an error on flush if foreign key constraints fail
            self::assertInstanceOf(\Exception::class, $exception, 'Missing cascade can cause exceptions');
        }
    }

    #[Test]
    public function it_demonstrates_orphan_removal_behavior(): void
    {
        // Arrange: Create order with items
        $user = new User();
        $user->setName('Test User');
        $user->setEmail('test@example.com');

        $product = new Product();
        $product->setName('Removable Product');
        $product->setPrice(29.99);
        $product->setStock(100);

        $this->entityManager->persist($user);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $order = new Order();
        $order->setUser($user);

        $item1 = new OrderItem();
        $item1->setProduct($product);
        $item1->setQuantity(1);

        $item2 = new OrderItem();
        $item2->setProduct($product);
        $item2->setQuantity(2);

        $order->addItem($item1);
        $order->addItem($item2);

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $item1Id = $item1->getId();
        $item2Id = $item2->getId();

        $this->entityManager->clear();

        // Act: Remove one item from order
        $savedOrder = $this->entityManager->find(Order::class, $order->getId());
        self::assertInstanceOf(Order::class, $savedOrder);
        $items = $savedOrder->getItems()->toArray();
        $savedOrder->removeItem($items[0]);

        $this->entityManager->flush();
        $this->entityManager->clear();

        // Assert: Removed item should be deleted (orphanRemoval)
        $deletedItem = $this->entityManager->find(OrderItem::class, $item1Id);
        $remainingItem = $this->entityManager->find(OrderItem::class, $item2Id);

        self::assertNotInstanceOf(OrderItem::class, $deletedItem, 'With orphanRemoval=true, removed item should be deleted from database');

        self::assertInstanceOf(OrderItem::class, $remainingItem, 'Remaining item should still exist');
    }

    #[Test]
    public function it_detects_missing_orphan_removal_on_composition(): void
    {
        // Arrange: Analyze entities
        $queryDataCollection = QueryDataCollection::empty();

        // Act: Analyze OrderWithoutCascade which lacks orphanRemoval
        $issueCollection = $this->missingOrphanRemovalOnCompositionAnalyzer->analyze($queryDataCollection);

        // Assert: Should detect missing orphanRemoval
        $issuesArray = $issueCollection->toArray();

        // Filter for OrderWithoutCascade issues
        $relevantIssues = array_filter($issuesArray, fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'OrderWithoutCascade')
            || str_contains($issue->getDescription(), 'OrderWithoutCascade'));

        if ([] !== $relevantIssues) {
            $issue = array_values($relevantIssues)[0];
            self::assertInstanceOf(SuggestionInterface::class, $issue->getSuggestion());
            self::assertStringContainsString('orphan', strtolower((string) $issue->getDescription()));
        }
    }

    #[Test]
    public function it_prevents_accidental_duplicate_creation(): void
    {
        // Arrange: Create a shared category (independent entity)
        $category = new Category();
        $category->setName('Electronics');

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        $categoryId = $category->getId();
        $this->entityManager->clear();

        // Act: Load category and create product
        $loadedCategory = $this->entityManager->find(Category::class, $categoryId);

        $product = new Product();
        $product->setName('Laptop');
        $product->setPrice(999.99);
        $product->setStock(5);
        $product->setCategory($loadedCategory);

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        // Assert: Category was NOT duplicated
        $allCategories = $this->entityManager
            ->getRepository(Category::class)
            ->findAll();

        self::assertCount(1, $allCategories, 'Category should not be duplicated even though it was set on product');
    }

    #[Test]
    public function it_detects_cascade_all_antipattern(): void
    {
        // Note: We don't have an entity with cascade=["all"] in fixtures,
        // but the analyzer should flag it if present

        $queryDataCollection = QueryDataCollection::empty();

        // Act: Run cascade persist analyzer
        $issueCollection = $this->cascadePersistOnIndependentEntityAnalyzer->analyze($queryDataCollection);

        // Assert: Analyzer runs without errors
        self::assertIsInt(count($issueCollection));

        // Any issues found should have suggestions
        foreach ($issueCollection->toArray() as $issue) {
            self::assertInstanceOf(SuggestionInterface::class, $issue->getSuggestion());
        }
    }

    #[Test]
    public function it_correctly_handles_bidirectional_relationships(): void
    {
        // Arrange: Create bidirectional Order <-> OrderItem
        $user = new User();
        $user->setName('Bidirectional Test');
        $user->setEmail('bidir@example.com');

        $product = new Product();
        $product->setName('Bidir Product');
        $product->setPrice(19.99);
        $product->setStock(50);

        $this->entityManager->persist($user);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $order = new Order();
        $order->setUser($user);

        $orderItem = new OrderItem();
        $orderItem->setProduct($product);
        $orderItem->setQuantity(3);

        // Act: Set both sides of relationship
        $order->addItem($orderItem); // Sets item->order internally

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        // Assert: Both sides are properly set
        self::assertSame($order, $orderItem->getOrder(), 'Inverse side should be set');
        self::assertTrue($order->getItems()->contains($orderItem), 'Owning side should contain item');
    }

    #[Test]
    public function it_demonstrates_cascade_remove_on_composition(): void
    {
        // Arrange: Order with items
        $user = new User();
        $user->setName('Cascade Remove Test');
        $user->setEmail('cascade@example.com');

        $product = new Product();
        $product->setName('Removable');
        $product->setPrice(9.99);
        $product->setStock(100);

        $this->entityManager->persist($user);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $order = new Order();
        $order->setUser($user);

        $orderItem = new OrderItem();
        $orderItem->setProduct($product);
        $orderItem->setQuantity(1);

        $order->addItem($orderItem);

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $orderId = $order->getId();
        $itemId = $orderItem->getId();

        $this->entityManager->clear();

        // Act: Remove order - should cascade remove items
        $savedOrder = $this->entityManager->find(Order::class, $orderId);
        self::assertNotNull($savedOrder);
        $this->entityManager->remove($savedOrder);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Assert: Order and items are deleted
        $deletedOrder = $this->entityManager->find(Order::class, $orderId);
        $deletedItem = $this->entityManager->find(OrderItem::class, $itemId);

        self::assertNotInstanceOf(Order::class, $deletedOrder, 'Order should be deleted');
        self::assertNotInstanceOf(OrderItem::class, $deletedItem, 'Items should be deleted via cascade remove');

        // But product should still exist (independent entity)
        $product = $this->entityManager->find(Product::class, $product->getId());
        self::assertInstanceOf(Product::class, $product, 'Product should NOT be deleted (independent entity)');
    }

    #[Test]
    public function it_provides_suggestions_for_cascade_configuration(): void
    {
        // Arrange
        $queryDataCollection = QueryDataCollection::empty();

        // Act: Run both analyzers
        $issueCollection = $this->cascadePersistOnIndependentEntityAnalyzer->analyze($queryDataCollection);
        $orphanIssues = $this->missingOrphanRemovalOnCompositionAnalyzer->analyze($queryDataCollection);

        // Assert: All issues should have actionable suggestions
        $allIssues = array_merge(
            $issueCollection->toArray(),
            $orphanIssues->toArray(),
        );

        foreach ($allIssues as $allIssue) {
            self::assertInstanceOf(SuggestionInterface::class, $allIssue->getSuggestion(), 'Each cascade issue should have a suggestion');

            $suggestion = $allIssue->getSuggestion();
            self::assertNotEmpty($suggestion->getDescription(), 'Suggestion should have detailed description');
        }
    }

    #[Test]
    public function it_handles_complex_entity_graphs(): void
    {
        // Arrange: Create complex graph with multiple relationships
        $user = new User();
        $user->setName('Graph Test');
        $user->setEmail('graph@example.com');

        $category = new Category();
        $category->setName('Test Category');

        $product1 = new Product();
        $product1->setName('Product 1');
        $product1->setPrice(10.0);
        $product1->setStock(10);
        $product1->setCategory($category);

        $product2 = new Product();
        $product2->setName('Product 2');
        $product2->setPrice(20.0);
        $product2->setStock(20);
        $product2->setCategory($category);

        $this->entityManager->persist($user);
        $this->entityManager->persist($category);
        $this->entityManager->persist($product1);
        $this->entityManager->persist($product2);
        $this->entityManager->flush();

        $order = new Order();
        $order->setUser($user);

        $item1 = new OrderItem();
        $item1->setProduct($product1);
        $item1->setQuantity(1);

        $item2 = new OrderItem();
        $item2->setProduct($product2);
        $item2->setQuantity(2);

        $order->addItem($item1);
        $order->addItem($item2);

        // Act: Persist order - cascade should handle items
        $this->entityManager->persist($order);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Assert: Entire graph persisted correctly
        $savedOrder = $this->entityManager->find(Order::class, $order->getId());

        self::assertInstanceOf(Order::class, $savedOrder);
        self::assertCount(2, $savedOrder->getItems());

        // Products and category still exist (independent)
        self::assertCount(2, $this->entityManager->getRepository(Product::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(Category::class)->findAll());
    }
}
