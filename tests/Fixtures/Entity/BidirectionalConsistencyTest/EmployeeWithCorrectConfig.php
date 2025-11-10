<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BidirectionalConsistencyTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Child entity with CORRECT configuration.
 */
#[ORM\Entity]
#[ORM\Table(name: 'employees_correct_config')]
class EmployeeWithCorrectConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: CompanyWithCorrectConfig::class, inversedBy: 'employees')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CompanyWithCorrectConfig $company = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getCompany(): ?CompanyWithCorrectConfig
    {
        return $this->company;
    }

    public function setCompany(?CompanyWithCorrectConfig $company): void
    {
        $this->company = $company;
    }
}
