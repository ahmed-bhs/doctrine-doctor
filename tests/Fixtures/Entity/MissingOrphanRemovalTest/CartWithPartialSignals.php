<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
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
 * Entity with 2 composition signals (just enough to trigger detection).
 *
 * Signals:
 * 1. cascade="remove" ✓
 * 2. Child name "CartItem" contains "Item" ✓
 * 3. Foreign key IS nullable ✗
 *
 * Total: 2 signals = composition → SHOULD have orphanRemoval=true
 */
#[ORM\Entity]
#[ORM\Table(name: 'carts_partial')]
class CartWithPartialSignals
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $sessionId;

    /**
     * MISSING orphanRemoval: Has 2 signals (cascade remove + "Item" name).
     */
    #[ORM\OneToMany(
        targetEntity: CartItemPartial::class,
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
