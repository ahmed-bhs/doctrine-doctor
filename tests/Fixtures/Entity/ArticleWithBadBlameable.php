<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Test entity with bad blameable practices.
 */
#[ORM\Entity]
class ArticleWithBadBlameable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string')]
    private string $title;

    // 📢 BAD: Nullable createdBy (should be NOT NULL)
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $updatedBy = null;

    public function __construct(string $title)
    {
        $this->title = $title;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    // 📢 BAD: Public setter on blameable field
    public function setCreatedBy(?User $user): void
    {
        $this->createdBy = $user;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    // 📢 BAD: Public setter on blameable field
    public function setUpdatedBy(?User $user): void
    {
        $this->updatedBy = $user;
    }
}
