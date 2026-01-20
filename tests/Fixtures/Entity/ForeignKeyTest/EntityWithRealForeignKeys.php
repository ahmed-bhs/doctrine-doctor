<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ForeignKeyTest;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entity with TRUE foreign key fields that should be detected.
 * These are legitimate anti-patterns that need fixing.
 */
#[ORM\Entity]
class EntityWithRealForeignKeys
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // LEGITIMATE FOREIGN KEYS (should be detected as issues)
    #[ORM\Column(type: 'integer')]
    private int $userId; // Should be ManyToOne relation

    #[ORM\Column(type: 'integer')]
    private int $customerId; // Should be ManyToOne relation

    #[ORM\Column(type: 'integer')]
    private int $productId; // Should be ManyToOne relation

    #[ORM\Column(type: 'integer')]
    private int $categoryId; // Should be ManyToOne relation

    #[ORM\Column(type: 'integer')]
    private int $authorId; // Should be ManyToOne relation

    #[ORM\Column(type: 'integer')]
    private int $countryId; // Should be ManyToOne relation

    #[ORM\Column(type: 'integer')]
    private int $currencyId; // Should be ManyToOne relation

    // Edge cases
    #[ORM\Column(type: 'integer')]
    private int $user_uid; // Should be ManyToOne relation

    #[ORM\Column(type: 'integer')]
    private int $teamId; // Should be ManyToOne relation

    // This one should NOT be detected (has proper relation)
    #[ORM\ManyToOne(targetEntity: EntityWithRealForeignKeys::class)]
    #[ORM\JoinColumn(name: 'proper_relation_id')]
    private ?EntityWithRealForeignKeys $properRelation = null;
}
