<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CompositeKeyTest;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_items')]
class OrderItemWithCompositeKey
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: OrderWithSurrogateKey::class)]
    #[ORM\JoinColumn(nullable: false)]
    private OrderWithSurrogateKey $order;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ProductWithSurrogateKey::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ProductWithSurrogateKey $product;

    #[ORM\Column]
    private int $quantity = 1;
}
