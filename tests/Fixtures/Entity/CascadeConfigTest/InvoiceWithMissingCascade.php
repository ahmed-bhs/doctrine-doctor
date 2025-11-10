<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeConfigTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entity with composition relationship but NO cascade (MISSING CASCADE).
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoices_missing_cascade')]
class InvoiceWithMissingCascade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $invoiceNumber;

    /**
     * MISSING CASCADE: OneToMany to composition entity (LineItem) without cascade.
     * LineItem should be persisted/removed with Invoice.
     */
    #[ORM\OneToMany(
        targetEntity: LineItemCascadeConfig::class,
        mappedBy: 'invoice',
    )]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
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

    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(LineItemCascadeConfig $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setInvoice($this);
        }
    }
}
