<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OnDeleteCascadeMismatchTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * CORRECT: Both ORM cascade=remove AND DB onDelete=CASCADE.
 *
 * No mismatch - behavior is consistent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'projects_correct_config')]
class ProjectWithCorrectConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $name;

    /**
     * CORRECT: ORM cascade=remove matches DB onDelete=CASCADE.
     */
    #[ORM\OneToMany(
        targetEntity: TaskWithCorrectConfig::class,
        mappedBy: 'project',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $tasks;

    public function __construct()
    {
        $this->tasks = new ArrayCollection();
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

    public function getTasks(): Collection
    {
        return $this->tasks;
    }
}
