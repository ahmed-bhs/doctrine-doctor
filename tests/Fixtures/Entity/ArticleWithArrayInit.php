<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Article entity with array initialization - FOR TESTING.
 * This entity uses array initialization instead of ArrayCollection.
 */
#[ORM\Entity]
#[ORM\Table(name: 'articles_array_init')]
class ArticleWithArrayInit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    /**
     * GOOD: Collection initialized with array notation.
     */
    #[ORM\OneToMany(targetEntity: Comment::class, mappedBy: 'article')]
    private Collection $comments;

    public function __construct()
    {
        // GOOD: Initialize with array (will be converted to ArrayCollection by Doctrine)
        $this->comments = [];
    }

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

    public function getComments(): Collection
    {
        return $this->comments;
    }
}
