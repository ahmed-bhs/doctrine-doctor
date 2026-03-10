<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CompositeKeyTest;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'price_matrix')]
class PriceMatrixWithHeavyCompositeKey
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ProductWithSurrogateKey::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ProductWithSurrogateKey $product;

    #[ORM\Id]
    #[ORM\Column(length: 3)]
    private string $region;

    #[ORM\Id]
    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price;
}
