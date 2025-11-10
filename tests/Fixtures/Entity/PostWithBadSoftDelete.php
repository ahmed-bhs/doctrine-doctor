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
 * Test entity with bad soft delete practices.
 */
#[ORM\Entity]
class PostWithBadSoftDelete
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string')]
    private string $title;

    // 📢 CRITICAL: NOT nullable (soft delete requires nullable!)
    #[ORM\Column(type: 'datetime', nullable: false)]
    private \DateTime $deletedAt;

    // 📢 BAD: Mutable DateTime
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $removedAt = null;

    // 📢 BAD: CASCADE DELETE conflicts with soft delete
    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Category $category = null;

    public function __construct(string $title)
    {
        $this->title = $title;
        $this->deletedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDeletedAt(): \DateTime
    {
        return $this->deletedAt;
    }

    // 📢 BAD: Public setter on soft delete field
    public function setDeletedAt(\DateTime $deletedAt): void
    {
        $this->deletedAt = $deletedAt;
    }

    public function getRemovedAt(): ?\DateTime
    {
        return $this->removedAt;
    }

    // 📢 BAD: Public setter on soft delete field
    public function setRemovedAt(?\DateTime $removedAt): void
    {
        $this->removedAt = $removedAt;
    }
}
