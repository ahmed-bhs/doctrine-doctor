<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\JoinTypeVoIdTest;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'organization_with_vo_id')]
class OrganizationWithVoId
{
    #[ORM\Embedded(class: OrganizationId::class, columnPrefix: false)]
    public OrganizationId $id;

    #[ORM\Column(length: 255)]
    public string $name = '';

    public function __construct()
    {
        $this->id = new OrganizationId();
    }
}
