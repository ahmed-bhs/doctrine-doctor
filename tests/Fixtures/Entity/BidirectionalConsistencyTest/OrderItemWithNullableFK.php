<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BidirectionalConsistencyTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Child entity with NULLABLE FK (conflicts with parent's orphanRemoval).
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_items_nullable_fk')]
class OrderItemWithNullableFK
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $productName;

    #[ORM\ManyToOne(targetEntity: OrderWithOrphanRemovalNullable::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: true)]
    private ?OrderWithOrphanRemovalNullable $order = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function setProductName(string $productName): void
    {
        $this->productName = $productName;
    }

    public function getOrder(): ?OrderWithOrphanRemovalNullable
    {
        return $this->order;
    }

    public function setOrder(?OrderWithOrphanRemovalNullable $order): void
    {
        $this->order = $order;
    }
}
