<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
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
 * Mismatch Type 3: DB onDelete=CASCADE but no ORM cascade=remove.
 *
 * Problem: SQL DELETE cascades, but $em->remove() does not.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoices_db_cascade_no_orm')]
class InvoiceWithDbCascadeNoOrm
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $invoiceNumber;

    /**
     * MISMATCH: Child has onDelete=CASCADE but parent has no cascade=remove.
     */
    #[ORM\OneToMany(
        targetEntity: InvoiceLineWithCascade::class,
        mappedBy: 'invoice',
        cascade: ['persist'],
    )]
    private Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoiceNumber(): string
    {
        return $this->invoiceNumber;
    }

    public function setInvoiceNumber(string $invoiceNumber): void
    {
        $this->invoiceNumber = $invoiceNumber;
    }

    public function getLines(): Collection
    {
        return $this->lines;
    }
}
