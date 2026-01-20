<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeConfigTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entity with cascade="all" on association to independent entity (DANGEROUS).
 */
#[ORM\Entity]
#[ORM\Table(name: 'orders_cascade_all')]
class OrderWithCascadeAll
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $orderNumber;

    /**
     * DANGEROUS: cascade="all" on OneToMany to independent entity (Customer).
     * This will DELETE the customer when you delete the order!
     */
    #[ORM\OneToMany(
        targetEntity: CustomerCascadeConfig::class,
        mappedBy: 'order',
        cascade: ['all'],
    )]
    private Collection $customers;

    public function __construct()
    {
        $this->customers = new ArrayCollection();
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

    public function getCustomers(): Collection
    {
        return $this->customers;
    }

    public function addCustomer(CustomerCascadeConfig $customer): void
    {
        if (!$this->customers->contains($customer)) {
            $this->customers->add($customer);
            $customer->setOrder($this);
        }
    }
}
