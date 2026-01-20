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
 * Entity using the deprecated 'object' type - should be flagged as CRITICAL.
 */
#[ORM\Entity]
class EntityWithObjectType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Using 'object' type - CRITICAL issue
     * Uses serialize() which is insecure and fragile.
     */
    #[ORM\Column(type: 'object')]
    private object $metadata;

    /**
     * Another field with object type.
     */
    #[ORM\Column(type: 'object')]
    private object $configuration;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMetadata(): object
    {
        return $this->metadata;
    }

    public function setMetadata(object $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getConfiguration(): object
    {
        return $this->configuration;
    }

    public function setConfiguration(object $configuration): self
    {
        $this->configuration = $configuration;

        return $this;
    }
}
