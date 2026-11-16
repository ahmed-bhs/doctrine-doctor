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
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Project\App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;

final class ShippingService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<mixed> */
    public function pendingLines(int $orderId): array
    {
        $order = $this->entityManager->getRepository(Order::class)->find($orderId);

        return $order?->getLines()->filter(static fn ($line): bool => !$line->isShipped())->getValues() ?? [];
    }

    public function addressCount(Customer $customer): int
    {
        return $customer->getAddresses()->count();
    }
}
