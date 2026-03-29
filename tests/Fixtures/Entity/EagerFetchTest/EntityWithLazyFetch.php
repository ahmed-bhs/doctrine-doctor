<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\EagerFetchTest;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entity with default lazy loading - should NOT be flagged.
 */
#[ORM\Entity]
class EntityWithLazyFetch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\ManyToOne(targetEntity: EntityWithEagerFetch::class, inversedBy: 'items')]
    private ?EntityWithEagerFetch $parent = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getParent(): ?EntityWithEagerFetch
    {
        return $this->parent;
    }

    public function setParent(?EntityWithEagerFetch $parent): self
    {
        $this->parent = $parent;

        return $this;
    }
}
