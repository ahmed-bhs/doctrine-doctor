<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\EventSubscriber;

use AhmedBhs\DoctrineDoctor\Collector\ServiceHolder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Clears ServiceHolder after each request for worker mode compatibility.
 *
 * In persistent PHP runtimes (FrankenPHP worker mode, RoadRunner, Swoole),
 * PHP processes persist between requests. ServiceHolder stores service
 * references in a static array that should be cleared after each request.
 *
 * Note: ServiceHolderData no longer stores EntityManager or other Doctrine
 * objects that could become stale. It only contains stateless services.
 * This subscriber provides defense-in-depth cleanup.
 *
 * @see \Symfony\Component\HttpKernel\EventListener\ProfilerListener
 */
class WorkerModeResetSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['onKernelTerminate', -2048],
        ];
    }

    public function onKernelTerminate(): void
    {
        ServiceHolder::clearAll();
    }
}
