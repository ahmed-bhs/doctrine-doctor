<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\JoinColumnTest;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'entity_with_non_pk_join')]
class EntityWithNonPkJoin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TargetWithCompositeKey::class)]
    #[ORM\JoinColumn(name: 'target_code', referencedColumnName: 'code')]
    private ?TargetWithCompositeKey $target = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTarget(): ?TargetWithCompositeKey
    {
        return $this->target;
    }
}
