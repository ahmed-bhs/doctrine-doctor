<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity;

use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Embeddable\PublicPropertiesMoney;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test entity using embeddable with public properties.
 */
#[ORM\Entity]
class OrderWithPublicPropertiesMoney
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Embedded(class: PublicPropertiesMoney::class, columnPrefix: 'price_')]
    private PublicPropertiesMoney $price;

    public function __construct(PublicPropertiesMoney $price)
    {
        $this->price = $price;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPrice(): PublicPropertiesMoney
    {
        return $this->price;
    }
}
