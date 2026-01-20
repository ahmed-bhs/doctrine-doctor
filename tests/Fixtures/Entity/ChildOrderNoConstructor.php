<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Child class with NO constructor that extends BaseOrderWithInit.
 * Tests that analyzer correctly uses parent constructor when child has none.
 *
 * This is the EXACT scenario of Sylius: App\Entity\Order has no constructor
 * but extends classes that do have constructors with initialization.
 *
 * This should NOT trigger warnings because parent constructor is automatically called
 * and it initializes the collections (items, tags).
 */
#[ORM\Entity]
#[ORM\Table(name: 'child_order_no_constructor')]
class ChildOrderNoConstructor extends BaseOrderWithInit
{
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $trackingNumber = null;

    // NO constructor - PHP automatically calls parent::__construct()

    public function getTrackingNumber(): ?string
    {
        return $this->trackingNumber;
    }

    public function setTrackingNumber(?string $trackingNumber): void
    {
        $this->trackingNumber = $trackingNumber;
    }
}
