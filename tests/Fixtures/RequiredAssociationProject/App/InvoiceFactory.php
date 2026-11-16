<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App;

use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\RequiredAssociationTest\Customer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\RequiredAssociationTest\Invoice;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\RequiredAssociationTest\Payment;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\RequiredAssociationTest\Shipment;
use Doctrine\ORM\EntityManagerInterface;

final class InvoiceFactory
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function forGuest(Invoice $invoice): void
    {
        $invoice->setCustomer(new Customer());
    }

    public function forKnownCustomer(Invoice $invoice): void
    {
        $customer = new Customer();
        $this->entityManager->persist($customer);
        $invoice->setCustomer($customer);
    }

    public function deposit(Payment $payment): void
    {
        $payment->setInvoice(new Invoice());
    }

    public function shipment(Shipment $shipment): void
    {
        $shipment->setCustomer(new Customer());
    }
}
