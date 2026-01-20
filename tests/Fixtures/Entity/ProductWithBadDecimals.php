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
 * Test entity with various decimal precision issues.
 */
#[ORM\Entity]
#[ORM\Table(name: 'products_bad_decimals')]
class ProductWithBadDecimals
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    // Missing precision/scale
    #[ORM\Column(type: 'decimal')]
    private string $priceWithoutPrecision;

    // Insufficient precision for money (should be at least 10,2)
    #[ORM\Column(type: 'decimal', precision: 8, scale: 1)]
    private string $amount;

    // Unusual scale for money (should be 2 or 4)
    #[ORM\Column(type: 'decimal', precision: 10, scale: 3)]
    private string $cost;

    // Excessive precision (not a money field name)
    #[ORM\Column(type: 'decimal', precision: 35, scale: 5)]
    private string $measurementValue;

    // Insufficient precision for percentage
    #[ORM\Column(type: 'decimal', precision: 3, scale: 1)]
    private string $discountPercentage;

    // Good example (should not be flagged)
    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $correctPrice;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getPriceWithoutPrecision(): string
    {
        return $this->priceWithoutPrecision;
    }

    public function setPriceWithoutPrecision(string $priceWithoutPrecision): void
    {
        $this->priceWithoutPrecision = $priceWithoutPrecision;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): void
    {
        $this->amount = $amount;
    }

    public function getCost(): string
    {
        return $this->cost;
    }

    public function setCost(string $cost): void
    {
        $this->cost = $cost;
    }

    public function getMeasurementValue(): string
    {
        return $this->measurementValue;
    }

    public function setMeasurementValue(string $measurementValue): void
    {
        $this->measurementValue = $measurementValue;
    }

    public function getDiscountPercentage(): string
    {
        return $this->discountPercentage;
    }

    public function setDiscountPercentage(string $discountPercentage): void
    {
        $this->discountPercentage = $discountPercentage;
    }

    public function getCorrectPrice(): string
    {
        return $this->correctPrice;
    }

    public function setCorrectPrice(string $correctPrice): void
    {
        $this->correctPrice = $correctPrice;
    }
}
