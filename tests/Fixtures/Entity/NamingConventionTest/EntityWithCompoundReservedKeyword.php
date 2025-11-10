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
 * Table and column with compound names containing reserved keywords
 * (should NOT trigger issues since they contain underscores).
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
class EntityWithCompoundReservedKeyword
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'user_name', type: 'string')]
    private string $userName;

    #[ORM\Column(name: 'user_group', type: 'string')]
    private string $userGroup;
}
