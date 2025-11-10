<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrphanRemovalTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entity with orphanRemoval=true but NO cascade="remove" (INCOMPLETE).
 *
 * This creates inconsistent behavior:
 * - $order->getItems()->removeElement($item); $em->flush(); → Item deleted ✓
 * - $em->remove($order); $em->flush(); → Items NOT deleted ✗
 */
#[ORM\Entity]
#[ORM\Table(name: 'orders_orphan_only')]
class OrderWithOrphanRemovalOnly
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $orderNumber;

    /**
     * INCOMPLETE: orphanRemoval=true but NO cascade="remove".
     * Removing from collection deletes items, but deleting order does NOT.
     * Note: We explicitly set cascade=["persist"] to prevent Doctrine from auto-adding remove.
     */
    #[ORM\OneToMany(
        targetEntity: OrderItemOrphan::class,
        mappedBy: 'order',
        cascade: ['persist'],
        orphanRemoval: true,
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

    public function addItem(OrderItemOrphan $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setOrder($this);
        }
    }

    public function removeItem(OrderItemOrphan $item): void
    {
        $this->items->removeElement($item);
    }
}
