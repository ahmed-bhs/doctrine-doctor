<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data;

use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Order;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrderItem;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Loads realistic Order data for testing cascade and orphan removal.
 */
class OrderFixture implements FixtureInterface
{
    /** @var array<Order> */
    private array $orders = [];

    /** @var array<OrderItem> */
    private array $orderItems = [];

    /**
     * @param array<User> $users
     * @param array<Product> $products
     */
    public function __construct(
        /**
         * @readonly
         */
        private array $users,
        /**
         * @readonly
         */
        private array $products,
    ) {
    }

    public function load(EntityManagerInterface $em): void
    {
        if (empty($this->users) || empty($this->products)) {
            throw new \RuntimeException('OrderFixture requires users and products to be loaded first');
        }

        $statuses = ['pending', 'processing', 'completed', 'cancelled'];

        // Create 15 orders with items
        for ($i = 0; $i < 15; $i++) {
            $order = new Order();
            $order->setUser($this->users[$i % count($this->users)]);
            $order->setStatus($statuses[$i % count($statuses)]);

            // Add 2-5 items per order
            $itemCount = rand(2, 5);
            for ($j = 0; $j < $itemCount; $j++) {
                $item = new OrderItem();
                $item->setProduct($this->products[array_rand($this->products)]);
                $item->setQuantity(rand(1, 3));
                $item->setOrder($order);

                $order->addItem($item);
                $this->orderItems[] = $item;
            }

            $em->persist($order);
            $this->orders[] = $order;
        }
    }

    public function getLoadedEntities(): array
    {
        return array_merge($this->orders, $this->orderItems);
    }

    /**
     * @return array<Order>
     */
    public function getOrders(): array
    {
        return $this->orders;
    }

    /**
     * @return array<OrderItem>
     */
    public function getOrderItems(): array
    {
        return $this->orderItems;
    }
}
