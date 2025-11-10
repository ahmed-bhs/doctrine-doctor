<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * OrderItem entity for testing cascade issues.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_items_without_cascade')]
class OrderItemWithoutCascade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrderWithoutCascade::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private OrderWithoutCascade $order;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Product $product;

    #[ORM\Column(type: 'integer')]
    private int $quantity = 1;

    #[ORM\Column(type: 'float')]
    private float $unitPrice;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): OrderWithoutCascade
    {
        return $this->order;
    }

    public function setOrder(OrderWithoutCascade $order): self
    {
        $this->order = $order;
        return $this;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): self
    {
        $this->product = $product;
        $this->unitPrice = $product->getPrice();
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getUnitPrice(): float
    {
        return $this->unitPrice;
    }
}
