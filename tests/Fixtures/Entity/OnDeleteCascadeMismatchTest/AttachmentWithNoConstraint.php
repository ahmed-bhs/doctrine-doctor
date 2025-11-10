<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OnDeleteCascadeMismatchTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Child entity with NO onDelete constraint (but parent has ORM cascade).
 */
#[ORM\Entity]
#[ORM\Table(name: 'attachments_no_constraint')]
class AttachmentWithNoConstraint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $fileName;

    #[ORM\ManyToOne(targetEntity: DocumentWithOrmCascadeNoDb::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?DocumentWithOrmCascadeNoDb $document = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function setFileName(string $fileName): void
    {
        $this->fileName = $fileName;
    }

    public function getDocument(): ?DocumentWithOrmCascadeNoDb
    {
        return $this->document;
    }

    public function setDocument(?DocumentWithOrmCascadeNoDb $document): void
    {
        $this->document = $document;
    }
}
