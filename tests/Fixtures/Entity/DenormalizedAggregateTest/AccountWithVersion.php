<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\DenormalizedAggregateTest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class AccountWithVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 0;

    #[ORM\Column(type: 'integer')]
    private int $balance = 0;

    /** @var Collection<int, VersionedEntry> */
    #[ORM\OneToMany(targetEntity: VersionedEntry::class, mappedBy: 'account', cascade: ['persist'])]
    private Collection $entries;

    public function __construct()
    {
        $this->entries = new ArrayCollection();
    }

    public function addEntry(int $amount): void
    {
        $this->entries[] = new VersionedEntry($this, $amount);
        $this->balance += $amount;
    }
}
