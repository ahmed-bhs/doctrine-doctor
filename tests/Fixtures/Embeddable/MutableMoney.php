<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Embeddable;

use Doctrine\ORM\Mapping as ORM;

/**
 * Test embeddable that is mutable (should trigger EmbeddableMutabilityAnalyzer).
 */
#[ORM\Embeddable]
class MutableMoney
{
    #[ORM\Column(type: 'integer')]
    private int $amount;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    public function __construct(int $amount, string $currency)
    {
        $this->amount = $amount;
        $this->currency = $currency;
    }

    // PROBLEM: Setter method makes it mutable
    public function setAmount(int $amount): void
    {
        $this->amount = $amount;
    }

    // PROBLEM: Setter method makes it mutable
    public function setCurrency(string $currency): void
    {
        $this->currency = $currency;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }
}
