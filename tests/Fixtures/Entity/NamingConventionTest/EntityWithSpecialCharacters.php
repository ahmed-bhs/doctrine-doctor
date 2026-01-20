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
 * Table and column with special characters.
 */
#[ORM\Entity]
#[ORM\Table(name: 'special-table')]
class EntityWithSpecialCharacters
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'special-column', type: 'string')]
    private string $specialColumn;

    #[ORM\Column(name: 'another@column', type: 'string')]
    private string $anotherColumn;
}
