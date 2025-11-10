<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeAllTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test fixture - Dependent entity (Comment).
 *
 * Comments depend on BlogPost and should be cascade deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'comments')]
class CommentEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\ManyToOne(targetEntity: BlogPostWithGoodCascade::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: true)]
    private ?BlogPostWithGoodCascade $post = null;

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

    public function getPost(): ?BlogPostWithGoodCascade
    {
        return $this->post;
    }

    public function setPost(?BlogPostWithGoodCascade $post): void
    {
        $this->post = $post;
    }
}
