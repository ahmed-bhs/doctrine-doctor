<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\InheritanceIntegrityTest;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class StiPhone extends StiDevice
{
    #[ORM\Column(length: 20)]
    private string $imei = '';
}
