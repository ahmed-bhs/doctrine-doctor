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
 * Child entity with onDelete=CASCADE (but parent has no ORM cascade).
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_lines_with_cascade')]
class InvoiceLineWithCascade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $description;

    #[ORM\ManyToOne(targetEntity: InvoiceWithDbCascadeNoOrm::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?InvoiceWithDbCascadeNoOrm $invoice = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getInvoice(): ?InvoiceWithDbCascadeNoOrm
    {
        return $this->invoice;
    }

    public function setInvoice(?InvoiceWithDbCascadeNoOrm $invoice): void
    {
        $this->invoice = $invoice;
    }
}
