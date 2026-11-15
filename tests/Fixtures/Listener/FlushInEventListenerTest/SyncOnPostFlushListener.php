<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Listener\FlushInEventListenerTest;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;

final class SyncOnPostFlushListener
{
    /** @var list<object> */
    private array $pending = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $entity) {
            $this->entityManager->persist($entity);
            $this->entityManager->flush();
        }
    }
}
