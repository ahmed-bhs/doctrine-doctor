<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BidirectionalConsistencyTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * CORRECT: All configurations are consistent.
 *
 * - orphanRemoval=true with nullable=false
 * - cascade="remove" with onDelete="CASCADE"
 * - cascade="persist" is present
 */
#[ORM\Entity]
#[ORM\Table(name: 'companies_correct_config')]
class CompanyWithCorrectConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $name;

    /**
     * CORRECT: Full cascade with orphanRemoval and child has nullable=false + onDelete=CASCADE.
     */
    #[ORM\OneToMany(
        targetEntity: EmployeeWithCorrectConfig::class,
        mappedBy: 'company',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $employees;

    public function __construct()
    {
        $this->employees = new ArrayCollection();
    }

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

    public function getEmployees(): Collection
    {
        return $this->employees;
    }
}
