<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Listener\FlushInEventListenerTest;

use Doctrine\ORM\Event\PostUpdateEventArgs;

final class FlushingEntityListener
{
    public function postUpdate(object $entity, PostUpdateEventArgs $args): void
    {
        $args->getObjectManager()->flush();
    }
}
