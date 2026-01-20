<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeRemoveTest;

use Doctrine\ORM\Mapping as ORM;

/**
 * Test fixture: User with ManyToOne to Profile where profile_id has UNIQUE constraint.
 *
 * This is effectively a 1:1 relationship even though mapped as ManyToOne:
 * - UNIQUE constraint on profile_id FK enforces 1:1 at database level
 * - Each User has exactly one Profile
 * - cascade="remove" is APPROPRIATE here
 *
 * This should NOT be flagged because UNIQUE constraint indicates 1:1 composition.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'user_profile_with_unique',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_profile_id', columns: ['profile_id']),
    ],
)]
class UserProfileWithUnique
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $username;

    /**
     * ManyToOne with cascade="remove" + UNIQUE constraint on FK.
     * This is ACCEPTABLE - UNIQUE makes it effectively 1:1.
     */
    #[ORM\ManyToOne(targetEntity: ProfileWithUnique::class, cascade: ['remove'])]
    #[ORM\JoinColumn(name: 'profile_id', nullable: true)]
    private ?ProfileWithUnique $profile = null;

    public function __construct(string $username)
    {
        $this->username = $username;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getProfile(): ?ProfileWithUnique
    {
        return $this->profile;
    }

    public function setProfile(?ProfileWithUnique $profile): void
    {
        $this->profile = $profile;
    }
}
