<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\MissingOrphanRemovalTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entity with independent relationship (NOT composition, should not trigger issues).
 *
 * Signals:
 * 1. NO cascade="remove" ✗
 * 2. Child name "Comment" is NOT a composition pattern ✗
 * 3. Foreign key IS nullable ✗
 *
 * Total: 0 signals = NOT composition → Should NOT require orphanRemoval
 */
#[ORM\Entity]
#[ORM\Table(name: 'users_independent')]
class UserWithIndependentRelation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $username;

    /**
     * Independent relationship: Comments can exist without User.
     * No composition signals, so no orphanRemoval needed.
     */
    #[ORM\OneToMany(
        targetEntity: CommentMissing::class,
        mappedBy: 'author',
        cascade: ['persist'],
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

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    public function getComments(): Collection
    {
        return $this->comments;
    }
}
