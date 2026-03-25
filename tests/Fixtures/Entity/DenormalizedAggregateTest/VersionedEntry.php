<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\DenormalizedAggregateTest;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class VersionedEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AccountWithVersion::class, inversedBy: 'entries')]
    private AccountWithVersion $account;

    #[ORM\Column(type: 'integer')]
    private int $amount;

    public function __construct(AccountWithVersion $account, int $amount)
    {
        $this->account = $account;
        $this->amount = $amount;
    }
}
