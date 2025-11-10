<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OnDeleteCascadeMismatchTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mismatch Type 1: ORM cascade=remove but DB onDelete=SET NULL.
 *
 * Problem: $em->remove($order) deletes items, but SQL DELETE sets FK to NULL.
 */
#[ORM\Entity]
#[ORM\Table(name: 'orders_orm_cascade_db_setnull')]
class OrderWithOrmCascadeDbSetNull
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $orderNumber;

    /**
     * MISMATCH: ORM cascade=remove but child has onDelete=SET NULL.
     */
    #[ORM\OneToMany(
        targetEntity: OrderItemWithSetNull::class,
        mappedBy: 'order',
        cascade: ['persist', 'remove'],
    )]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
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

    public function getItems(): Collection
    {
        return $this->items;
    }
}
