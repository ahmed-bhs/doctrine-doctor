<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ArticleComment entity for testing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'article_comments')]
class ArticleComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\ManyToOne(targetEntity: ArticleWithConstructorButNoInit::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: false)]
    private ArticleWithConstructorButNoInit $article;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getArticle(): ArticleWithConstructorButNoInit
    {
        return $this->article;
    }

    public function setArticle(ArticleWithConstructorButNoInit $article): self
    {
        $this->article = $article;
        return $this;
    }
}
