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
 * Test embeddable with float for money (should trigger FloatInMoneyEmbeddableAnalyzer).
 */
#[ORM\Embeddable]
class MoneyWithFloat
{
    public function __construct(
        /**
         * @readonly
         */
        #[ORM\Column(type: 'float')] // CRITICAL PROBLEM: Float for money!
        private float $amount,
        /**
         * @readonly
         */
        #[ORM\Column(type: 'string', length: 3)]
        private string $currency,
    ) {
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }
}
