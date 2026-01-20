<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BidirectionalConsistencyTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Child entity with onDelete="SET NULL" (conflicts with parent's cascade="remove").
 */
#[ORM\Entity]
#[ORM\Table(name: 'cart_items_set_null')]
class CartItemWithSetNull
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $productName;

    #[ORM\ManyToOne(targetEntity: CartWithCascadeRemoveSetNull::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CartWithCascadeRemoveSetNull $cart = null;

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

    public function getCart(): ?CartWithCascadeRemoveSetNull
    {
        return $this->cart;
    }

    public function setCart(?CartWithCascadeRemoveSetNull $cart): void
    {
        $this->cart = $cart;
    }
}
