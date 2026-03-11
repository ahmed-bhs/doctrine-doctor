<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OneToOneInverseSideTest;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'oto_capital_cities')]
class CapitalCity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name = '';

    #[ORM\OneToOne(targetEntity: Country::class, inversedBy: 'capitalCity')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Country $country = null;
}
