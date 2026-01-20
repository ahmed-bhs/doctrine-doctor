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
 * Article entity WITH constructor but WITHOUT collection initialization - FOR TESTING.
 * This entity has a constructor but doesn't initialize collections.
 */
#[ORM\Entity]
#[ORM\Table(name: 'articles_constructor_no_init')]
class ArticleWithConstructorButNoInit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;

    /**
     * BAD: Collection is not initialized in constructor!
     */
    #[ORM\OneToMany(targetEntity: ArticleComment::class, mappedBy: 'article')]
    private Collection $comments;

    public function __construct()
    {
        // GOOD: Initialize other properties
        $this->createdAt = new \DateTime();

        // BAD: Forgot to initialize $comments collection!
        // $this->comments = new ArrayCollection();
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

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function getComments(): Collection
    {
        return $this->comments;
    }
}
