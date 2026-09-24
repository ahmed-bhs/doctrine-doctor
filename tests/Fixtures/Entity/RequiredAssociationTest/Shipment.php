<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\RequiredAssociationTest;

use Doctrine\ORM\Mapping as ORM;

/**
 * Required association with cascade persist and a non-nullable property: nothing to report.
 */
#[ORM\Entity]
#[ORM\Table(name: 'required_assoc_shipments')]
class Shipment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Customer::class, cascade: ['persist'])]
        #[ORM\JoinColumn(nullable: false)]
        private Customer $customer,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }
}
