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
 * Entity using the 'array' type - should be flagged as WARNING.
 */
#[ORM\Entity]
class EntityWithArrayType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Using 'array' type - WARNING issue
     * Uses serialize() which breaks with class changes.
     */
    #[ORM\Column(type: 'array')]
    private array $settings;

    /**
     * Another array field.
     */
    #[ORM\Column(type: 'array')]
    private array $permissions;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function setSettings(array $settings): self
    {
        $this->settings = $settings;

        return $this;
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function setPermissions(array $permissions): self
    {
        $this->permissions = $permissions;

        return $this;
    }
}
