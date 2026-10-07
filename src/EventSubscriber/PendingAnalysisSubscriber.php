<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\EventSubscriber;

use AhmedBhs\DoctrineDoctor\Collector\PendingProfileAnalyzer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runs the deferred Doctrine Doctor analysis when a profile is opened.
 *
 * With AnalysisTiming::OnView the profiled request only stores its queries.
 * The web debug toolbar is fetched asynchronously after the page has loaded,
 * so analyzing here keeps the cost off the profiled request entirely.
 */
final readonly class PendingAnalysisSubscriber implements EventSubscriberInterface
{
    private const array PROFILER_ROUTES = ['_wdt', '_profiler'];

    /**
     * @param \Closure(): PendingProfileAnalyzer $pendingProfileAnalyzer
     */
    public function __construct(
        // Lazy: kernel.request listeners are instantiated before the firewall runs.
        // Building the analyzer there (profiler, every collector, Twig and its globals)
        // would construct application services that read the user before authentication.
        private \Closure $pendingProfileAnalyzer,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the RouterListener (32), before the profiler controllers read the profile
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $token = $request->attributes->get('token');

        if (!\in_array($request->attributes->get('_route'), self::PROFILER_ROUTES, true)
            || !\is_string($token)
            || \in_array($token, ['', 'latest', 'empty'], true)
        ) {
            return;
        }

        ($this->pendingProfileAnalyzer)()->analyze($token);
    }
}
