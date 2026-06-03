<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\JoinTypeVoIdTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'member_with_organizations')]
class MemberWithOrganizations
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, OrganizationWithVoId> */
    #[ORM\ManyToMany(targetEntity: OrganizationWithVoId::class)]
    public Collection $organizations;

    public function __construct()
    {
        $this->organizations = new ArrayCollection();
    }
}
