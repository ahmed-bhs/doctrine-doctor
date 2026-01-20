<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
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
 * Mismatch Type 2: ORM orphanRemoval=true but DB onDelete=SET NULL.
 *
 * Problem: ORM wants to delete orphans, DB wants to set FK to NULL.
 */
#[ORM\Entity]
#[ORM\Table(name: 'carts_orphan_db_setnull')]
class CartWithOrphanRemovalDbSetNull
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $sessionId;

    /**
     * MISMATCH: ORM orphanRemoval=true but child has onDelete=SET NULL.
     */
    #[ORM\OneToMany(
        targetEntity: CartItemWithSetNull::class,
        mappedBy: 'cart',
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
