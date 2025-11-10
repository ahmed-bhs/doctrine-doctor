<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CascadeConfigTest;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Composition entity - LineItem.
 * Typically named "Item" pattern, so should have cascade.
 */
#[ORM\Entity]
#[ORM\Table(name: 'line_items_cascade_config')]
class LineItemCascadeConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $productName;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $price;

    #[ORM\ManyToOne(targetEntity: InvoiceWithMissingCascade::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private InvoiceWithMissingCascade $invoice;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function setProductName(string $productName): void
    {
        $this->productName = $productName;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): void
    {
        $this->price = $price;
    }

    public function getInvoice(): InvoiceWithMissingCascade
    {
        return $this->invoice;
    }

    public function setInvoice(InvoiceWithMissingCascade $invoice): void
    {
        $this->invoice = $invoice;
    }
}
