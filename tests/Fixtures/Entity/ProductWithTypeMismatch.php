<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Test entity for PropertyTypeMismatchAnalyzer.
 * Properties are intentionally set with wrong types to test type checking.
 */
#[ORM\Entity]
#[ORM\Table(name: 'products_type_mismatch')]
class ProductWithTypeMismatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // Property declared as int
    #[ORM\Column(type: 'integer')]
    private int $quantity;

    // Property declared as string
    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    // Non-nullable string
    #[ORM\Column(type: 'string', length: 50, nullable: false)]
    private string $sku;

    // Nullable field (should not flag when null)
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $description = null;

    // Correct type - should not be flagged
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): void
    {
        $this->sku = $sku;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): void
    {
        $this->price = $price;
    }
}
