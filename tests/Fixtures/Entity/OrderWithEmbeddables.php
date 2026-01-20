<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity;

use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Embeddable\IncompleteMoney;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Embeddable\MoneyWithFloat;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Embeddable\MutableMoney;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test entity using various problematic embeddables.
 */
#[ORM\Entity]
class OrderWithEmbeddables
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Embedded(class: MutableMoney::class, columnPrefix: 'mutable_')]
    private MutableMoney $mutablePrice;

    #[ORM\Embedded(class: IncompleteMoney::class, columnPrefix: 'incomplete_')]
    private IncompleteMoney $incompletePrice;

    #[ORM\Embedded(class: MoneyWithFloat::class, columnPrefix: 'float_')]
    private MoneyWithFloat $floatPrice;

    public function __construct(
        MutableMoney $mutablePrice,
        IncompleteMoney $incompletePrice,
        MoneyWithFloat $floatPrice,
    ) {
        $this->mutablePrice = $mutablePrice;
        $this->incompletePrice = $incompletePrice;
        $this->floatPrice = $floatPrice;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
