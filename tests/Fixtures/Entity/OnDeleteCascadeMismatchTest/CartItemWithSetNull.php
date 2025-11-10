<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OnDeleteCascadeMismatchTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Child entity with onDelete=SET NULL (conflicts with parent orphanRemoval).
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
    private string $productSku;

    #[ORM\ManyToOne(targetEntity: CartWithOrphanRemovalDbSetNull::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CartWithOrphanRemovalDbSetNull $cart = null;

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

    public function getCart(): ?CartWithOrphanRemovalDbSetNull
    {
        return $this->cart;
    }

    public function setCart(?CartWithOrphanRemovalDbSetNull $cart): void
    {
        $this->cart = $cart;
    }
}
