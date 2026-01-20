<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\MissingOrphanRemovalTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Composition entity - OrderItem (NOT NULL FK).
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_items_missing')]
class OrderItemMissing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $productSku;

    #[ORM\ManyToOne(targetEntity: OrderWithMissingOrphanRemoval::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private OrderWithMissingOrphanRemoval $order;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductSku(): string
    {
        return $this->productSku;
    }

    public function setProductSku(string $productSku): void
    {
        $this->productSku = $productSku;
    }

    public function getOrder(): OrderWithMissingOrphanRemoval
    {
        return $this->order;
    }

    public function setOrder(OrderWithMissingOrphanRemoval $order): void
    {
        $this->order = $order;
    }
}
