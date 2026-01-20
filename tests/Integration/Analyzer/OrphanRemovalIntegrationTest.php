<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data\OrderFixture;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data\ProductFixture;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data\UserFixture;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Category;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Order;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrderItem;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test demonstrating orphan removal and cascade operations.
 *
 * This is a great example of testing real Doctrine behavior:
 * - Real cascade persist/remove
 * - Real orphan removal
 * - Real database state verification
 */
final class OrphanRemovalIntegrationTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->createSchema([
            User::class,
            Category::class,
            Product::class,
            Order::class,
            OrderItem::class,
        ]);

        // Load real data
        $userFixture = new UserFixture();
        $userFixture->load($this->entityManager);

        $productFixture = new ProductFixture();
        $productFixture->load($this->entityManager);

        $orderFixture = new OrderFixture(
            $userFixture->getUsers(),
            $productFixture->getProducts(),
        );
        $orderFixture->load($this->entityManager);

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    #[Test]
    public function it_demonstrates_orphan_removal_in_action(): void
    {
        // Get an order with items
        $order = $this->entityManager
            ->getRepository(Order::class)
            ->findOneBy([]);

        self::assertInstanceOf(Order::class, $order, 'Should have at least one order');

        $initialItemCount = $order->getItems()->count();
        self::assertGreaterThan(0, $initialItemCount, 'Order should have items');

        // Count items in database
        $itemCountBefore = $this->entityManager
            ->getRepository(OrderItem::class)
            ->count([]);

        // Remove one item from the order
        $itemToRemove = $order->getItems()->first();
        $order->removeItem($itemToRemove);

        $this->entityManager->flush();
        $this->entityManager->clear();

        // Verify the item was DELETED from database (orphanRemoval in action)
        $itemCountAfter = $this->entityManager
            ->getRepository(OrderItem::class)
            ->count([]);

        self::assertSame($itemCountBefore - 1, $itemCountAfter, 'OrphanRemoval should DELETE the item from database');
    }

    #[Test]
    public function it_demonstrates_cascade_persist(): void
    {
        $user = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy([]);

        $product = $this->entityManager
            ->getRepository(Product::class)
            ->findOneBy([]);

        self::assertInstanceOf(User::class, $user);
        self::assertInstanceOf(Product::class, $product);

        // Create a new order with items WITHOUT explicitly persisting items
        $order = new Order();
        $order->setUser($user);
        $order->setStatus('pending');

        $item1 = new OrderItem();
        $item1->setProduct($product);
        $item1->setQuantity(2);

        $order->addItem($item1);

        $item2 = new OrderItem();
        $item2->setProduct($product);
        $item2->setQuantity(3);

        $order->addItem($item2);

        // Only persist the order, NOT the items
        $this->entityManager->persist($order);

        // Count items before flush
        $itemCountBefore = $this->entityManager
            ->getRepository(OrderItem::class)
            ->count([]);

        // Flush
        $this->entityManager->flush();

        // Count items after flush
        $itemCountAfter = $this->entityManager
            ->getRepository(OrderItem::class)
            ->count([]);

        // Items should be persisted automatically (cascade persist)
        self::assertSame($itemCountBefore + 2, $itemCountAfter, 'Cascade persist should save items automatically');
    }

    #[Test]
    public function it_demonstrates_cascade_remove(): void
    {
        // Get an order
        $order = $this->entityManager
            ->getRepository(Order::class)
            ->findOneBy([]);

        self::assertInstanceOf(Order::class, $order);

        $itemCount = $order->getItems()->count();
        self::assertGreaterThan(0, $itemCount);

        // Count total items in database
        $totalItemsBefore = $this->entityManager
            ->getRepository(OrderItem::class)
            ->count([]);

        // Remove the order (should cascade remove all items)
        $this->entityManager->remove($order);
        $this->entityManager->flush();

        // Count items after
        $totalItemsAfter = $this->entityManager
            ->getRepository(OrderItem::class)
            ->count([]);

        // All items from this order should be deleted
        self::assertEquals($totalItemsBefore - $itemCount, $totalItemsAfter, 'Cascade remove should delete all order items');
    }

    #[Test]
    public function it_shows_composition_relationship_lifecycle(): void
    {
        $user = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy([]);

        $product = $this->entityManager
            ->getRepository(Product::class)
            ->findOneBy([]);

        // Create order (parent)
        $order = new Order();
        self::assertNotNull($order);
        self::assertNotNull($user);
        self::assertNotNull($product);
        $order->setUser($user);
        $order->setStatus('pending');

        // Create items (children - composition)
        $orderItem = new OrderItem();
        $orderItem->setProduct($product);
        $orderItem->setQuantity(1);

        $order->addItem($orderItem);

        // Persist only parent
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $orderId = $order->getId();

        // Clear and reload
        $this->entityManager->clear();

        $reloadedOrder = $this->entityManager->find(Order::class, $orderId);
        self::assertInstanceOf(Order::class, $reloadedOrder);
        self::assertCount(1, $reloadedOrder->getItems());

        // Remove item (orphan removal)
        $reloadedOrder->getItems()->clear();
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Reload again
        $finalOrder = $this->entityManager->find(Order::class, $orderId);
        self::assertInstanceOf(Order::class, $finalOrder);
        self::assertCount(0, $finalOrder->getItems(), 'Items should be deleted by orphan removal');

        // Delete order (cascade remove)
        $this->entityManager->remove($finalOrder);
        $this->entityManager->flush();

        // Verify order is gone
        $deletedOrder = $this->entityManager->find(Order::class, $orderId);
        self::assertNotInstanceOf(Order::class, $deletedOrder, 'Order should be deleted');
    }

    #[Test]
    public function it_monitors_queries_during_cascade_operations(): void
    {
        $user = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy([]);

        $product = $this->entityManager
            ->getRepository(Product::class)
            ->findOneBy([]);

        // Count items before
        $itemCountBefore = $this->entityManager
            ->getRepository(OrderItem::class)
            ->count([]);

        // Create order with 3 items
        $order = new Order();
        self::assertNotNull($user);
        self::assertNotNull($product);
        $order->setUser($user);
        $order->setStatus('pending');

        for ($idx = 0; $idx < 3; $idx++) {
            $item = new OrderItem();
            $item->setProduct($product);
            $item->setQuantity($idx + 1);
            $order->addItem($item);
        }

        // Only persist order - items should cascade
        $this->entityManager->persist($order);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Verify cascade worked - should have 3 more items
        $itemCountAfter = $this->entityManager
            ->getRepository(OrderItem::class)
            ->count([]);

        self::assertSame($itemCountBefore + 3, $itemCountAfter, 'Cascade persist should save all 3 items automatically');

        // Verify order was saved
        $savedOrder = $this->entityManager->find(Order::class, $order->getId());
        self::assertInstanceOf(Order::class, $savedOrder, 'Order should be persisted');
        self::assertCount(3, $savedOrder->getItems(), 'Order should have 3 items');
    }
}
