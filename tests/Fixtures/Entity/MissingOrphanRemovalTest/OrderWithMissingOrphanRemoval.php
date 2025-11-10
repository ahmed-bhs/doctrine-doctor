<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\MissingOrphanRemovalTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entity with composition relationship but MISSING orphanRemoval.
 *
 * Signals:
 * 1. cascade="remove" ✓
 * 2. Child name "OrderItem" contains "Item" ✓
 * 3. Foreign key NOT NULL ✓
 *
 * Total: 3 signals = composition → SHOULD have orphanRemoval=true
 */
#[ORM\Entity]
#[ORM\Table(name: 'orders_missing_orphan')]
class OrderWithMissingOrphanRemoval
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $orderNumber;

    /**
     * MISSING orphanRemoval on composition relationship.
     * Has cascade="remove", child name is "OrderItem", FK is NOT NULL.
     */
    #[ORM\OneToMany(
        targetEntity: OrderItemMissing::class,
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

    public function addItem(OrderItemMissing $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setOrder($this);
        }
    }

    public function removeItem(OrderItemMissing $item): void
    {
        $this->items->removeElement($item);
    }
}
