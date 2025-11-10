<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeAllTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test fixture for CascadeAllAnalyzer - entity with dangerous cascade="all".
 *
 * This entity demonstrates CRITICAL issues:
 * - ManyToOne with cascade="all" to independent entity (Customer)
 * - Deleting Order would DELETE the Customer! 💥
 */
#[ORM\Entity]
#[ORM\Table(name: 'orders_with_cascade_all')]
class OrderWithCascadeAll
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $orderNumber;

    /**
     * CRITICAL: ManyToOne with cascade="all" to independent entity.
     * This is extremely dangerous - deleting the order will DELETE the customer!
     */
    #[ORM\ManyToOne(targetEntity: CustomerEntity::class, cascade: ['all'])]
    #[ORM\JoinColumn(nullable: false)]
    private CustomerEntity $customer;

    /**
     * CRITICAL: ManyToMany with cascade="all" to independent entity.
     * This will create duplicate products and delete them unintentionally.
     */
    #[ORM\ManyToMany(targetEntity: ProductEntity::class, cascade: ['all'])]
    #[ORM\JoinTable(name: 'order_products')]
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

    public function getCustomer(): CustomerEntity
    {
        return $this->customer;
    }

    public function setCustomer(CustomerEntity $customer): void
    {
        $this->customer = $customer;
    }

    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(ProductEntity $product): void
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
        }
    }

    public function removeProduct(ProductEntity $product): void
    {
        $this->products->removeElement($product);
    }
}
