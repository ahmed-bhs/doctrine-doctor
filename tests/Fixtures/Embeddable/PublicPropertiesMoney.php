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
 * Test embeddable with public mutable properties (should trigger EmbeddableMutabilityAnalyzer).
 */
#[ORM\Embeddable]
class PublicPropertiesMoney
{
    // PROBLEM: Public mutable property
    #[ORM\Column(type: 'integer')]
    public int $amount;

    // PROBLEM: Public mutable property
    #[ORM\Column(type: 'string', length: 3)]
    public string $currency;

    public function __construct(int $amount, string $currency)
    {
        $this->amount = $amount;
        $this->currency = $currency;
    }
}
