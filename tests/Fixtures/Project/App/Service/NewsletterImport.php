<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Project\App\Service;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;

final class NewsletterImport
{
    public function __construct(
        private readonly ManagerRegistry $registry,
    ) {
    }

    /** @param list<object> $rows */
    public function importAll(ObjectManager $manager, array $rows): void
    {
        foreach ($rows as $row) {
            try {
                $manager->persist($row);
                $manager->flush();
            } catch (\Throwable) {
                continue;
            }
        }
    }

    public function retryOnce(ObjectManager $manager, object $row): void
    {
        try {
            $manager->flush();
        } catch (\Throwable) {
            $manager->persist($row);
            $manager->flush();
        }
    }

    /** @param list<object> $rows */
    public function importWithReset(ObjectManager $manager, array $rows): void
    {
        foreach ($rows as $row) {
            try {
                $manager->persist($row);
                $manager->flush();
            } catch (\Throwable) {
                $manager = $this->registry->resetManager();
            }
        }
    }

    public function stopOnFailure(ObjectManager $manager, object $row): void
    {
        try {
            $manager->persist($row);
            $manager->flush();
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Import failed', 0, $exception);
        }
    }
}
