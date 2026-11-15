<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Listener\FlushInEventListenerTest;

use Doctrine\ORM\Event\PostFlushEventArgs;

final class SafePostFlushListener
{
    public int $flushes = 0;

    public function postFlush(PostFlushEventArgs $args): void
    {
        ++$this->flushes;
    }
}
