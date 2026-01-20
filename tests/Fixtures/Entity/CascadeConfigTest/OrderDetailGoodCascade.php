<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeConfigTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Composition entity - OrderDetail (uses "Detail" pattern).
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_details_good_cascade')]
class OrderDetailGoodCascade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $description;

    #[ORM\ManyToOne(targetEntity: OrderWithGoodCascade::class, inversedBy: 'details')]
    #[ORM\JoinColumn(nullable: false)]
    private OrderWithGoodCascade $order;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getOrder(): OrderWithGoodCascade
    {
        return $this->order;
    }

    public function setOrder(OrderWithGoodCascade $order): void
    {
        $this->order = $order;
    }
}
