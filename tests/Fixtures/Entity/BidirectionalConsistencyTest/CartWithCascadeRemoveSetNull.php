<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BidirectionalConsistencyTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Inconsistency Type 2: cascade="remove" but onDelete="SET NULL".
 *
 * Problem: ORM delete cascades, but SQL DELETE sets FK to NULL.
 */
#[ORM\Entity]
#[ORM\Table(name: 'carts_cascade_remove_set_null')]
class CartWithCascadeRemoveSetNull
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $sessionId;

    /**
     * INCONSISTENCY: cascade="remove" but child has onDelete="SET NULL".
     */
    #[ORM\OneToMany(
        targetEntity: CartItemWithSetNull::class,
        mappedBy: 'cart',
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

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getItems(): Collection
    {
        return $this->items;
    }
}
