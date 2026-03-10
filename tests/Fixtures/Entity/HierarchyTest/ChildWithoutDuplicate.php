<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\HierarchyTest;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'child_without_duplicate')]
class ChildWithoutDuplicate extends ParentEntity
{
    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    public function getEmail(): string
    {
        return $this->email;
    }
}
