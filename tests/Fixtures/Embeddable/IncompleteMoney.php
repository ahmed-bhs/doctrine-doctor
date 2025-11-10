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
 * Test embeddable missing Value Object methods (should trigger EmbeddableWithoutValueObjectAnalyzer).
 */
#[ORM\Embeddable]
class IncompleteMoney
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
        // PROBLEM: No validation
    }

    // PROBLEM: Missing equals() method
    // PROBLEM: Missing __toString() method

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }
}
