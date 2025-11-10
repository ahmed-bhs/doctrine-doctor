<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * BlogPost entity WITHOUT collection initialization - FOR TESTING ONLY.
 * This entity intentionally has uninitialized collections to test CollectionEmptyAccessAnalyzer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'blog_posts_no_init')]
class BlogPostWithoutCollectionInit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $author;

    /**
     * BAD: Collection is not initialized in constructor!
     * This will cause "Call to member function on null" errors.
     */
    #[ORM\OneToMany(targetEntity: CommentWithoutInit::class, mappedBy: 'post')]
    private Collection $comments;

    // BAD: No constructor initializing $comments!
    // public function __construct()
    // {
    //     $this->comments = new ArrayCollection();
    // }

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

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function setAuthor(User $author): self
    {
        $this->author = $author;
        return $this;
    }

    /**
     * BAD: This will return NULL if called before Doctrine initializes the collection!
     */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    /**
     * BAD: This will fail if $comments is NULL!
     */
    public function addComment(CommentWithoutInit $comment): self
    {
        if (!$this->comments->contains($comment)) {
            $this->comments[] = $comment;
            $comment->setPost($this);
        }
        return $this;
    }
}
