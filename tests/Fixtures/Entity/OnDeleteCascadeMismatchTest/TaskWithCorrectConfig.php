<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OnDeleteCascadeMismatchTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Child entity with onDelete=CASCADE matching parent cascade=remove.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tasks_correct_config')]
class TaskWithCorrectConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $title;

    #[ORM\ManyToOne(targetEntity: ProjectWithCorrectConfig::class, inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ProjectWithCorrectConfig $project = null;

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

    public function getProject(): ?ProjectWithCorrectConfig
    {
        return $this->project;
    }

    public function setProject(?ProjectWithCorrectConfig $project): void
    {
        $this->project = $project;
    }
}
