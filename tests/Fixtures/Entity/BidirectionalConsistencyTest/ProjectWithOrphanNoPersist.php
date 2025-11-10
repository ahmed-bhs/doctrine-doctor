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
 * Inconsistency Type 3: orphanRemoval=true but no cascade="persist".
 *
 * Problem: Can delete children, but can't automatically save new ones.
 */
#[ORM\Entity]
#[ORM\Table(name: 'projects_orphan_no_persist')]
class ProjectWithOrphanNoPersist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $name;

    /**
     * INCONSISTENCY: orphanRemoval=true but no cascade="persist".
     */
    #[ORM\OneToMany(
        targetEntity: TaskWithoutCascadePersist::class,
        mappedBy: 'project',
        cascade: ['remove'],
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
