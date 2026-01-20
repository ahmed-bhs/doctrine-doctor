<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\NamingConventionTest;

use Doctrine\ORM\Mapping as ORM;

/**
 * Foreign key without _id suffix and in camelCase.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_item')]
class EntityWithBadForeignKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EntityWithCorrectNaming::class)]
    #[ORM\JoinColumn(name: 'customerId', referencedColumnName: 'id')]
    private ?EntityWithCorrectNaming $customer = null;

    #[ORM\ManyToOne(targetEntity: EntityWithCorrectNaming::class)]
    #[ORM\JoinColumn(name: 'product', referencedColumnName: 'id')]
    private ?EntityWithCorrectNaming $product = null;
}
