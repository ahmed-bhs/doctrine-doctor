<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Project\App\Service;

use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Project\App\Entity\Customer;
use Doctrine\Persistence\ObjectRepository;

final class CustomerReport
{
    /**
     * @param ObjectRepository<Customer> $customers
     */
    public function __construct(
        private readonly ObjectRepository $customers,
    ) {
    }

    /** @return array<int, int> */
    public function addressesPerCustomer(): array
    {
        $counts = [];
        foreach ($this->customers->findAll() as $customer) {
            $counts[] = count($customer->getAddresses());
        }

        return $counts;
    }

    public function totalSpent(): int
    {
        $total = 0;
        foreach ($this->customers->findAll() as $customer) {
            $total += $customer->getSpent();
        }

        return $total;
    }

    public function noLoadingNoIssue(int $a, int $b): int
    {
        $total = 0;
        foreach ([$a, $b] as $value) {
            $total += $value;
        }

        return $total;
    }
}
