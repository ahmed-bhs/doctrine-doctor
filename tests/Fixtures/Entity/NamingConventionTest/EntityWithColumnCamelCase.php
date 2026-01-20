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
 * Column name in camelCase (should be snake_case).
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer')]
class EntityWithColumnCamelCase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'firstName', type: 'string')]
    private string $firstName;

    #[ORM\Column(name: 'lastName', type: 'string')]
    private string $lastName;
}
