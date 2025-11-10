<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\MissingOrphanRemovalTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Composition entity - CartItem (nullable FK).
 */
#[ORM\Entity]
#[ORM\Table(name: 'cart_items_partial')]
class CartItemPartial
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $productSku;

    #[ORM\ManyToOne(targetEntity: CartWithPartialSignals::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: true)]
    private ?CartWithPartialSignals $cart = null;

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

    public function getCart(): ?CartWithPartialSignals
    {
        return $this->cart;
    }

    public function setCart(?CartWithPartialSignals $cart): void
    {
        $this->cart = $cart;
    }
}
