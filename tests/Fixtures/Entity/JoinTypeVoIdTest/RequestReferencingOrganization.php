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
#[ORM\Table(name: 'request_referencing_organization')]
class RequestReferencingOrganization
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrganizationWithVoId::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    public ?OrganizationWithVoId $organization = null;

    /** @var Collection<int, RequestDocument> */
    #[ORM\OneToMany(targetEntity: RequestDocument::class, mappedBy: 'request')]
    public Collection $documents;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
    }
}
