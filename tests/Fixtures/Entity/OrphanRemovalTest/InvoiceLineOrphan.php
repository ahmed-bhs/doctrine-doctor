<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrphanRemovalTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Composition entity - InvoiceLine.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_lines_orphan')]
class InvoiceLineOrphan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $description;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $amount;

    #[ORM\ManyToOne(targetEntity: InvoiceWithCompleteConfiguration::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)]
    private InvoiceWithCompleteConfiguration $invoice;

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

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): void
    {
        $this->amount = $amount;
    }

    public function getInvoice(): InvoiceWithCompleteConfiguration
    {
        return $this->invoice;
    }

    public function setInvoice(InvoiceWithCompleteConfiguration $invoice): void
    {
        $this->invoice = $invoice;
    }
}
