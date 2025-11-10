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
 * Table and column use SQL reserved keywords.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order')]
class EntityWithReservedKeyword
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'user', type: 'string')]
    private string $user;

    #[ORM\Column(name: 'key', type: 'string')]
    private string $key;
}
