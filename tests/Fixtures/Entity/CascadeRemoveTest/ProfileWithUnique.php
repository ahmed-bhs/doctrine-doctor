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
 * Test fixture: Profile entity that has UNIQUE constraint via parent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'profile_with_unique')]
class ProfileWithUnique
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $bio;

    public function __construct(string $bio)
    {
        $this->bio = $bio;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBio(): string
    {
        return $this->bio;
    }
}
