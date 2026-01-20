<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OnDeleteCascadeMismatchTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Child entity with onDelete=SET NULL (conflicts with parent cascade=remove).
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_items_set_null')]
class OrderItemWithSetNull
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $productSku;

    #[ORM\ManyToOne(targetEntity: OrderWithOrmCascadeDbSetNull::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?OrderWithOrmCascadeDbSetNull $order = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductSku(): string
    {
        return $this->productSku;
    }

    public function setProductSku(string $productSku): void
    {
        $this->productSku = $productSku;
    }

    public function getOrder(): ?OrderWithOrmCascadeDbSetNull
    {
        return $this->order;
    }

    public function setOrder(?OrderWithOrmCascadeDbSetNull $order): void
    {
        $this->order = $order;
    }
}
