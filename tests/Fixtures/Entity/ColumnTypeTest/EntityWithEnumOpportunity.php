<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ColumnTypeTest;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entity with string fields that look like they should be enums.
 */
#[ORM\Entity]
class EntityWithEnumOpportunity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Status field - should suggest enum.
     */
    #[ORM\Column(type: 'string', length: 20)]
    private string $status;

    /**
     * Type field - should suggest enum.
     */
    #[ORM\Column(type: 'string', length: 50)]
    private string $type;

    /**
     * Role field - should suggest enum.
     */
    #[ORM\Column(type: 'string', length: 30)]
    private string $role;

    /**
     * Priority field - should suggest enum.
     */
    #[ORM\Column(type: 'string', length: 20)]
    private string $priority;

    /**
     * Name field - should NOT suggest enum (not enum-like).
     */
    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    /**
     * Description field - should NOT suggest enum.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $description;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }
}
