<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity;

use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Embeddable\MutableMoney;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test entity using mutable embeddable.
 */
#[ORM\Entity]
class OrderWithMutableMoney
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Embedded(class: MutableMoney::class, columnPrefix: 'price_')]
    private MutableMoney $price;

    public function __construct(MutableMoney $price)
    {
        $this->price = $price;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPrice(): MutableMoney
    {
        return $this->price;
    }
}
