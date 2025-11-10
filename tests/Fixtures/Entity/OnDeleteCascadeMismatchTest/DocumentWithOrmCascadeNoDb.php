<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OnDeleteCascadeMismatchTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mismatch Type 4: ORM cascade=remove but no DB onDelete constraint.
 *
 * Problem: ORM deletes work, but direct SQL DELETE may cause FK violations.
 */
#[ORM\Entity]
#[ORM\Table(name: 'documents_orm_cascade_no_db')]
class DocumentWithOrmCascadeNoDb
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $title;

    /**
     * MISMATCH: ORM cascade=remove but child has no onDelete constraint.
     */
    #[ORM\OneToMany(
        targetEntity: AttachmentWithNoConstraint::class,
        mappedBy: 'document',
        cascade: ['persist', 'remove'],
    )]
    private Collection $attachments;

    public function __construct()
    {
        $this->attachments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getAttachments(): Collection
    {
        return $this->attachments;
    }
}
