<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadePersistTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dependent entity - Comment.
 *
 * Comments are DEPENDENT on BlogPost, so cascade="persist" from BlogPost is appropriate.
 */
#[ORM\Entity]
#[ORM\Table(name: 'comments_persist_test')]
class CommentEntityPersist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\ManyToOne(targetEntity: BlogPostGoodCascade::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: false)]
    private BlogPostGoodCascade $post;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function getPost(): BlogPostGoodCascade
    {
        return $this->post;
    }

    public function setPost(BlogPostGoodCascade $post): void
    {
        $this->post = $post;
    }
}
