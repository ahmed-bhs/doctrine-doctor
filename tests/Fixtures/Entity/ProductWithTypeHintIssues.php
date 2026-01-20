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
 * Test entity with intentional type hint mismatches.
 * Used to test TypeHintMismatchAnalyzer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'products_type_hint_issues')]
class ProductWithTypeHintIssues
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // CRITICAL: decimal returns string, but typed as float
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $price;

    // WARNING: integer returns int, but typed as string
    #[ORM\Column(type: 'integer')]
    private string $quantity;

    // Correct: integer returns int, typed as int
    #[ORM\Column(type: 'integer')]
    private int $stock;

    // Correct: string returns string, typed as string
    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    // Correct: decimal returns string, typed as string
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $cost;

    // No type hint - should be skipped
    #[ORM\Column(type: 'string', length: 50)]
    private $sku;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): void
    {
        $this->price = $price;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getStock(): int
    {
        return $this->stock;
    }

    public function setStock(int $stock): void
    {
        $this->stock = $stock;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getCost(): string
    {
        return $this->cost;
    }

    public function setCost(string $cost): void
    {
        $this->cost = $cost;
    }

    public function getSku()
    {
        return $this->sku;
    }

    public function setSku($sku): void
    {
        $this->sku = $sku;
    }
}
