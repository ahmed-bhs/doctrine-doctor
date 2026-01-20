<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrphanRemovalTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Composition entity - OrderItem.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_items_orphan')]
class OrderItemOrphan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $productName;

    #[ORM\Column(type: Types::INTEGER)]
    private int $quantity;

    #[ORM\ManyToOne(targetEntity: OrderWithOrphanRemovalOnly::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private OrderWithOrphanRemovalOnly $order;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function setProductName(string $productName): void
    {
        $this->productName = $productName;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getOrder(): OrderWithOrphanRemovalOnly
    {
        return $this->order;
    }

    public function setOrder(OrderWithOrphanRemovalOnly $order): void
    {
        $this->order = $order;
    }
}
