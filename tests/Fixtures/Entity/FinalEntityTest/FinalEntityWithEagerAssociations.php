<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\FinalEntityTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Final entity with eager associations - should be flagged as WARNING (less severe).
 */
#[ORM\Entity]
final class FinalEntityWithEagerAssociations
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /**
     * Eager ManyToOne association - no proxy needed.
     */
    #[ORM\ManyToOne(targetEntity: RelatedEntity::class, fetch: 'EAGER')]
    private ?RelatedEntity $relatedEntity = null;

    /**
     * Eager OneToMany association - no proxy needed.
     *
     * @var Collection<int, RelatedEntity>
     */
    #[ORM\OneToMany(targetEntity: RelatedEntity::class, mappedBy: 'eagerEntity', fetch: 'EAGER')]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getRelatedEntity(): ?RelatedEntity
    {
        return $this->relatedEntity;
    }

    public function setRelatedEntity(?RelatedEntity $relatedEntity): self
    {
        $this->relatedEntity = $relatedEntity;

        return $this;
    }

    /**
     * @return Collection<int, RelatedEntity>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(RelatedEntity $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items[] = $item;
        }

        return $this;
    }
}
