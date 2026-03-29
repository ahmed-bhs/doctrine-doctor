<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\EagerFetchTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entity with fetch: 'EAGER' on associations - should be flagged.
 */
#[ORM\Entity]
class EntityWithEagerFetch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: EntityWithLazyFetch::class, fetch: 'EAGER')]
    private ?EntityWithLazyFetch $category = null;

    #[ORM\OneToMany(targetEntity: EntityWithLazyFetch::class, mappedBy: 'parent', fetch: 'EAGER')]
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

    public function getCategory(): ?EntityWithLazyFetch
    {
        return $this->category;
    }

    public function setCategory(?EntityWithLazyFetch $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(EntityWithLazyFetch $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setParent($this);
        }

        return $this;
    }

    public function removeItem(EntityWithLazyFetch $item): self
    {
        if ($this->items->removeElement($item)) {
            if ($item->getParent() === $this) {
                $item->setParent(null);
            }
        }

        return $this;
    }
}
