<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Embeddable;

use Doctrine\ORM\Mapping as ORM;

/**
 * Test embeddable that is properly immutable (should NOT trigger EmbeddableMutabilityAnalyzer).
 */
#[ORM\Embeddable]
class ImmutableMoney
{
    public function __construct(
        /**
         * @readonly
         */
        #[ORM\Column(type: 'integer')]
        private int $amount,
        /**
         * @readonly
         */
        #[ORM\Column(type: 'string', length: 3)]
        private string $currency,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('%d %s', $this->amount, $this->currency);
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Returns a new instance with a different amount (immutable pattern).
     */
    public function withAmount(int $amount): self
    {
        return new self($amount, $this->currency);
    }

    /**
     * Returns a new instance with a different currency (immutable pattern).
     */
    public function withCurrency(string $currency): self
    {
        return new self($this->amount, $currency);
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount
            && $this->currency === $other->currency;
    }
}
