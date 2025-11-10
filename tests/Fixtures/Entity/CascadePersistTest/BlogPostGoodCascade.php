<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadePersistTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test fixture - Entity with GOOD cascade configuration.
 *
 * NO cascade to independent entities (Author)
 * cascade="persist" only on composition (dependent children)
 */
#[ORM\Entity]
#[ORM\Table(name: 'blog_posts_good_persist')]
class BlogPostGoodCascade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $title;

    /**
     * GOOD: ManyToOne to independent entity WITHOUT cascade persist.
     * Author is loaded from database, not created.
     */
    #[ORM\ManyToOne(targetEntity: AuthorEntityPersist::class)]
    #[ORM\JoinColumn(nullable: false)]
    private AuthorEntityPersist $author;

    /**
     * GOOD: OneToMany with cascade="persist" to DEPENDENT children.
     * Comments belong to the post, so cascade persist is appropriate.
     */
    #[ORM\OneToMany(
        targetEntity: CommentEntityPersist::class,
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

    public function getAuthor(): AuthorEntityPersist
    {
        return $this->author;
    }

    public function setAuthor(AuthorEntityPersist $author): void
    {
        $this->author = $author;
    }

    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function addComment(CommentEntityPersist $comment): void
    {
        if (!$this->comments->contains($comment)) {
            $this->comments->add($comment);
            $comment->setPost($this);
        }
    }

    public function removeComment(CommentEntityPersist $comment): void
    {
        $this->comments->removeElement($comment);
    }
}
