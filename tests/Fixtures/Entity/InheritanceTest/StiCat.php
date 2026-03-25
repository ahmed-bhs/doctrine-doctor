<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\InheritanceTest;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class StiCat extends StiAnimal
{
    #[ORM\Column(nullable: true)]
    private ?bool $isIndoor = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $furColor = null;

    #[ORM\Column(nullable: true)]
    private ?int $clawLength = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isPurring = null;
}
