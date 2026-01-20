<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadePersistTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test fixture - Order with dangerous cascade="persist" to independent entities.
 *
 * CRITICAL ISSUE: cascade="persist" on Customer (independent entity)
 * This can create duplicate Customer records instead of using existing ones!
 */
#[ORM\Entity]
#[ORM\Table(name: 'orders_with_cascade_persist')]
class OrderWithCascadePersist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $orderNumber;

    /**
     * CRITICAL: cascade="persist" on independent entity (Customer).
     * Risk: Creating duplicate customers instead of loading existing ones!
     */
    #[ORM\ManyToOne(targetEntity: CustomerEntityPersist::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private CustomerEntityPersist $customer;

    /**
     * CRITICAL: cascade="persist" on independent entity (Product).
     * Risk: Creating duplicate products!
     */
    #[ORM\ManyToMany(targetEntity: ProductEntityPersist::class, cascade: ['persist'])]
    #[ORM\JoinTable(name: 'order_products_persist')]
    private Collection $products;

    public function __construct()
    {
        $this->products = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function setOrderNumber(string $orderNumber): void
    {
        $this->orderNumber = $orderNumber;
    }

    public function getCustomer(): CustomerEntityPersist
    {
        return $this->customer;
    }

    public function setCustomer(CustomerEntityPersist $customer): void
    {
        $this->customer = $customer;
    }

    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(ProductEntityPersist $product): void
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
        }
    }

    public function removeProduct(ProductEntityPersist $product): void
    {
        $this->products->removeElement($product);
    }
}
