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
 * Entity using 'simple_array' type with limited length - should be flagged as INFO.
 */
#[ORM\Entity]
class EntityWithSimpleArray
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Using simple_array with default length (255) - INFO issue
     * Limited length and cannot contain commas.
     */
    #[ORM\Column(type: 'simple_array')]
    private array $tags;

    /**
     * Simple array with explicit short length - should be flagged.
     */
    #[ORM\Column(type: 'simple_array', length: 100)]
    private array $categories;

    /**
     * Simple array with large length - should NOT be flagged.
     */
    #[ORM\Column(type: 'simple_array', length: 1000)]
    private array $keywords;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function setTags(array $tags): self
    {
        $this->tags = $tags;

        return $this;
    }

    public function getCategories(): array
    {
        return $this->categories;
    }

    public function setCategories(array $categories): self
    {
        $this->categories = $categories;

        return $this;
    }

    public function getKeywords(): array
    {
        return $this->keywords;
    }

    public function setKeywords(array $keywords): self
    {
        $this->keywords = $keywords;

        return $this;
    }
}
