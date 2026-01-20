<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BidirectionalConsistencyTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Child entity for testing orphanRemoval without cascade="persist".
 */
#[ORM\Entity]
#[ORM\Table(name: 'tasks_without_cascade_persist')]
class TaskWithoutCascadePersist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $title;

    #[ORM\ManyToOne(targetEntity: ProjectWithOrphanNoPersist::class, inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: false)]
    private ?ProjectWithOrphanNoPersist $project = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getProject(): ?ProjectWithOrphanNoPersist
    {
        return $this->project;
    }

    public function setProject(?ProjectWithOrphanNoPersist $project): void
    {
        $this->project = $project;
    }
}
