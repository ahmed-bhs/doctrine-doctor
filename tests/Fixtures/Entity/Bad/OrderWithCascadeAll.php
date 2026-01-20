<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Bad;

use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrderItem;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * BAD EXAMPLE: Order entity with cascade="all" - DO NOT USE IN PRODUCTION!
 * This is intentionally bad for testing the CascadeAllAnalyzer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'orders_cascade_all')]
class OrderWithCascadeAll
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $status = 'pending';

    /**
     * BAD: cascade="all" on ManyToOne to User (independent entity)
     * This would delete the User when the Order is deleted!
     */
    #[ORM\ManyToOne(targetEntity: User::class, cascade: ['all'])]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    /**
     * BAD: cascade="all" on OneToMany
     * Should use explicit cascades instead
     */
    #[ORM\OneToMany(
        targetEntity: OrderItem::class,
        mappedBy: 'order',
        cascade: ['all'],
    )]
    private Collection $items;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getItems(): Collection
    {
        return $this->items;
    }
}
