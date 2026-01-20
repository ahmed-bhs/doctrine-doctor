<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeRemoveTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test fixture - Entity with GOOD cascade configuration.
 *
 * NO cascade="remove" on ManyToOne to independent entities.
 * cascade="remove" ONLY on OneToMany to dependent children.
 */
#[ORM\Entity]
#[ORM\Table(name: 'blog_posts_good_remove')]
class BlogPostGoodRemove
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $title;

    /**
     * GOOD: ManyToOne to independent entity WITHOUT cascade="remove".
     * Author is NOT deleted when post is deleted.
     */
    #[ORM\ManyToOne(targetEntity: AuthorEntityRemove::class)]
    #[ORM\JoinColumn(nullable: false)]
    private AuthorEntityRemove $author;

    /**
     * GOOD: OneToMany with cascade="remove" to DEPENDENT children.
     * Comments belong to the post, so they SHOULD be deleted with the post.
     */
    #[ORM\OneToMany(
        targetEntity: CommentEntityRemove::class,
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

    public function getAuthor(): AuthorEntityRemove
    {
        return $this->author;
    }

    public function setAuthor(AuthorEntityRemove $author): void
    {
        $this->author = $author;
    }

    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function addComment(CommentEntityRemove $comment): void
    {
        if (!$this->comments->contains($comment)) {
            $this->comments->add($comment);
            $comment->setPost($this);
        }
    }

    public function removeComment(CommentEntityRemove $comment): void
    {
        $this->comments->removeElement($comment);
    }
}
