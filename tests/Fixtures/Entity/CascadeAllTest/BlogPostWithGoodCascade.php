<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeAllTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test fixture for CascadeAllAnalyzer - entity with GOOD cascade configuration.
 *
 * This entity demonstrates correct cascade usage:
 * - OneToMany with explicit cascades (persist, remove) for composition
 * - ManyToOne WITHOUT cascade for independent entity (author)
 */
#[ORM\Entity]
#[ORM\Table(name: 'blog_posts_with_good_cascade')]
class BlogPostWithGoodCascade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $title;

    /**
     * GOOD: ManyToOne WITHOUT cascade to independent entity (Author).
     * Author is independent - we don't want to delete them when post is deleted.
     */
    #[ORM\ManyToOne(targetEntity: AuthorEntity::class)]
    #[ORM\JoinColumn(nullable: false)]
    private AuthorEntity $author;

    /**
     * GOOD: OneToMany with explicit cascades for composition.
     * Comments belong to the post, so cascade persist and remove make sense.
     */
    #[ORM\OneToMany(
        targetEntity: CommentEntity::class,
        mappedBy: 'post',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $comments;

    public function __construct()
    {
        $this->comments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getAuthor(): AuthorEntity
    {
        return $this->author;
    }

    public function setAuthor(AuthorEntity $author): void
    {
        $this->author = $author;
    }

    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function addComment(CommentEntity $comment): void
    {
        if (!$this->comments->contains($comment)) {
            $this->comments->add($comment);
            $comment->setPost($this);
        }
    }

    public function removeComment(CommentEntity $comment): void
    {
        if ($this->comments->removeElement($comment)) {
            if ($comment->getPost() === $this) {
                $comment->setPost(null);
            }
        }
    }
}
