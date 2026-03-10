<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CompositeKeyTest;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'price_overrides')]
class PriceOverrideReferencingComposite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrderItemWithCompositeKey::class)]
    private ?OrderItemWithCompositeKey $orderItem = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $overridePrice = '0.00';
}
