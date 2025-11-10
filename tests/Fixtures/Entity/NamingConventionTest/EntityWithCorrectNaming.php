<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\NamingConventionTest;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entity with correct naming conventions.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'customer',
    indexes: [
        new ORM\Index(name: 'idx_email', columns: ['email']),
        new ORM\Index(name: 'idx_status_created_at', columns: ['status', 'created_at']),
    ],
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_email', columns: ['email']),
    ],
)]
class EntityWithCorrectNaming
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'first_name', type: 'string')]
    private string $firstName;

    #[ORM\Column(name: 'last_name', type: 'string')]
    private string $lastName;

    #[ORM\Column(type: 'string')]
    private string $email;

    #[ORM\Column(type: 'string')]
    private string $status;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTimeInterface $createdAt;
}
