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
 * Entity with GOOD cascade configuration.
 * No issues should be detected.
 */
#[ORM\Entity]
#[ORM\Table(name: 'orders_good_cascade')]
class OrderWithGoodCascade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $orderNumber;

    /**
     * GOOD: OneToMany with explicit cascade persist/remove to composition entity.
     */
    #[ORM\OneToMany(
        targetEntity: OrderDetailGoodCascade::class,
        mappedBy: 'order',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $details;

    /**
     * GOOD: ManyToOne to independent entity with NO cascade.
     */
    #[ORM\ManyToOne(targetEntity: UserCascadeConfig::class)]
    #[ORM\JoinColumn(nullable: false)]
    private UserCascadeConfig $user;

    public function __construct()
    {
        $this->details = new ArrayCollection();
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

    public function getDetails(): Collection
    {
        return $this->details;
    }

    public function addDetail(OrderDetailGoodCascade $detail): void
    {
        if (!$this->details->contains($detail)) {
            $this->details->add($detail);
            $detail->setOrder($this);
        }
    }

    public function getUser(): UserCascadeConfig
    {
        return $this->user;
    }

    public function setUser(UserCascadeConfig $user): void
    {
        $this->user = $user;
    }
}
