<?php

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
