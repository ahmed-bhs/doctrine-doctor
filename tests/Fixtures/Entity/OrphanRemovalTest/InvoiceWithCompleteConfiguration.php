<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrphanRemovalTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entity with COMPLETE configuration: orphanRemoval=true AND cascade="remove".
 *
 * This is CORRECT:
 * - $invoice->getLines()->removeElement($line); $em->flush(); → Line deleted ✓
 * - $em->remove($invoice); $em->flush(); → Lines deleted ✓
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoices_complete_config')]
class InvoiceWithCompleteConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $invoiceNumber;

    /**
     * COMPLETE: orphanRemoval=true AND cascade="remove".
     * Consistent behavior in all cases.
     */
    #[ORM\OneToMany(
        targetEntity: InvoiceLineOrphan::class,
        mappedBy: 'invoice',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
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

    public function addLine(InvoiceLineOrphan $line): void
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setInvoice($this);
        }
    }
}
