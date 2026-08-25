<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\DiscriminatorIntegrityTest;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'disc_integ_documents')]
#[ORM\Index(name: 'idx_disc_integ_documents_dtype', columns: ['dtype'])]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'dtype', type: 'string', length: 64)]
#[ORM\DiscriminatorMap([
    'invoice' => IndexedInvoice::class,
    'quote' => IndexedQuote::class,
])]
class IndexedDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
}
