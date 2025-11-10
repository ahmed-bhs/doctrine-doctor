<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\FinalEntityTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Final entity with lazy associations - should be flagged as CRITICAL.
 */
#[ORM\Entity]
final class FinalEntityWithLazyAssociations
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /**
     * Lazy ManyToOne association - will cause proxy errors.
     */
    #[ORM\ManyToOne(targetEntity: RelatedEntity::class)]
    private ?RelatedEntity $relatedEntity = null;

    /**
     * Lazy OneToMany association - will cause proxy errors.
     *
     * @var Collection<int, RelatedEntity>
     */
    #[ORM\OneToMany(targetEntity: RelatedEntity::class, mappedBy: 'finalEntity')]
    private Collection $items;

    /**
     * Lazy ManyToMany association - will cause proxy errors.
     *
     * @var Collection<int, RelatedEntity>
     */
    #[ORM\ManyToMany(targetEntity: RelatedEntity::class)]
    private Collection $tags;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->tags = new ArrayCollection();
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

    /**
     * @return Collection<int, RelatedEntity>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(RelatedEntity $tag): self
    {
        if (!$this->tags->contains($tag)) {
            $this->tags[] = $tag;
        }

        return $this;
    }
}
