<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\FinalEntityTest;

use Doctrine\ORM\Mapping as ORM;

/**
 * Related entity used in associations.
 */
#[ORM\Entity]
class RelatedEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: FinalEntityWithLazyAssociations::class, inversedBy: 'items')]
    private ?FinalEntityWithLazyAssociations $finalEntity = null;

    #[ORM\ManyToOne(targetEntity: FinalEntityWithEagerAssociations::class, inversedBy: 'items')]
    private ?FinalEntityWithEagerAssociations $eagerEntity = null;

    #[ORM\ManyToOne(targetEntity: NonFinalEntity::class, inversedBy: 'items')]
    private ?NonFinalEntity $nonFinalEntity = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getFinalEntity(): ?FinalEntityWithLazyAssociations
    {
        return $this->finalEntity;
    }

    public function setFinalEntity(?FinalEntityWithLazyAssociations $finalEntity): self
    {
        $this->finalEntity = $finalEntity;

        return $this;
    }

    public function getEagerEntity(): ?FinalEntityWithEagerAssociations
    {
        return $this->eagerEntity;
    }

    public function setEagerEntity(?FinalEntityWithEagerAssociations $eagerEntity): self
    {
        $this->eagerEntity = $eagerEntity;

        return $this;
    }

    public function getNonFinalEntity(): ?NonFinalEntity
    {
        return $this->nonFinalEntity;
    }

    public function setNonFinalEntity(?NonFinalEntity $nonFinalEntity): self
    {
        $this->nonFinalEntity = $nonFinalEntity;

        return $this;
    }
}
