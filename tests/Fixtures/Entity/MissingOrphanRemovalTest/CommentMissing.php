<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\MissingOrphanRemovalTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Independent entity - Comment (nullable FK).
 */
#[ORM\Entity]
#[ORM\Table(name: 'comments_missing')]
class CommentMissing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\ManyToOne(targetEntity: UserWithIndependentRelation::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: true)]
    private ?UserWithIndependentRelation $author = null;

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

    public function getAuthor(): ?UserWithIndependentRelation
    {
        return $this->author;
    }

    public function setAuthor(?UserWithIndependentRelation $author): void
    {
        $this->author = $author;
    }
}
