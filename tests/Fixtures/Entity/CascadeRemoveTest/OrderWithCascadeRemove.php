<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeRemoveTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test fixture - Order with DANGEROUS cascade="remove" to independent entities.
 *
 * 🚨 CRITICAL DANGER: cascade="remove" on ManyToOne to Customer
 * Deleting the order will DELETE the Customer! 💥
 */
#[ORM\Entity]
#[ORM\Table(name: 'orders_with_cascade_remove')]
class OrderWithCascadeRemove
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $orderNumber;

    /**
     * 🚨 CRITICAL: ManyToOne with cascade="remove" to independent entity (Customer).
     * This will DELETE the customer when you delete the order!
     * ALL OTHER ORDERS referencing this customer will lose their reference!
     */
    #[ORM\ManyToOne(targetEntity: CustomerEntityRemove::class, cascade: ['remove'])]
    #[ORM\JoinColumn(nullable: false)]
    private CustomerEntityRemove $customer;

    /**
     * HIGH: ManyToMany with cascade="remove" to independent entity (Product).
     * This will DELETE all products when you delete the order!
     */
    #[ORM\ManyToMany(targetEntity: ProductEntityRemove::class, cascade: ['remove'])]
    #[ORM\JoinTable(name: 'order_products_remove')]
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

    public function getCustomer(): CustomerEntityRemove
    {
        return $this->customer;
    }

    public function setCustomer(CustomerEntityRemove $customer): void
    {
        $this->customer = $customer;
    }

    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(ProductEntityRemove $product): void
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
        }
    }

    public function removeProduct(ProductEntityRemove $product): void
    {
        $this->products->removeElement($product);
    }
}
