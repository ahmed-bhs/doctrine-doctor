<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\NamingConventionTest;

use Doctrine\ORM\Mapping as ORM;

/**
 * Indexes without proper naming conventions.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'product',
    indexes: [
        new ORM\Index(name: 'email_index', columns: ['email']),
        new ORM\Index(name: 'StatusCreatedAt', columns: ['status', 'created_at']),
    ],
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'email_unique', columns: ['email']),
        new ORM\UniqueConstraint(name: 'skuConstraint', columns: ['sku']),
    ],
)]
class EntityWithBadIndexes
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string')]
    private string $email;

    #[ORM\Column(type: 'string')]
    private string $status;

    #[ORM\Column(type: 'string')]
    private string $sku;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTimeInterface $createdAt;
}
