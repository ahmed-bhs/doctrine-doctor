<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector\RowCount;

use Doctrine\ORM\Event\PostLoadEventArgs;

/**
 * Counts the entities the ORM builds from rows: an array or DTO query builds none.
 */
final readonly class EntityHydrationListener
{
    public function __construct(
        private RowCountRecorder $recorder,
    ) {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        $this->recorder->entityHydrated($args->getObjectManager()->getClassMetadata($entity::class)->getTableName());
    }
}
