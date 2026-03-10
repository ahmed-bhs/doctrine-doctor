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
#[ORM\Table(name: 'child_with_duplicate')]
class ChildWithDuplicateField extends ParentEntity
{
    private string $status;

    #[ORM\Column(type: 'string', length: 255)]
    private string $label;

    public function getChildStatus(): string
    {
        return $this->status;
    }

    public function getLabel(): string
    {
        return $this->label;
    }
}
