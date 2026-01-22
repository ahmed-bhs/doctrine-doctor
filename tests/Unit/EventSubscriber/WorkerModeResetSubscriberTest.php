<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\EventSubscriber;

use AhmedBhs\DoctrineDoctor\Collector\Helper\DataCollectorLogger;
use AhmedBhs\DoctrineDoctor\Collector\Helper\IssueReconstructor;
use AhmedBhs\DoctrineDoctor\Collector\Helper\QueryStatsCalculator;
use AhmedBhs\DoctrineDoctor\Collector\ServiceHolder;
use AhmedBhs\DoctrineDoctor\Collector\ServiceHolderData;
use AhmedBhs\DoctrineDoctor\EventSubscriber\WorkerModeResetSubscriber;
use AhmedBhs\DoctrineDoctor\Service\IssueDeduplicator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Unit tests for WorkerModeResetSubscriber.
 */
final class WorkerModeResetSubscriberTest extends TestCase
{
    private WorkerModeResetSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new WorkerModeResetSubscriber();
        ServiceHolder::clearAll();
    }

    protected function tearDown(): void
    {
        ServiceHolder::clearAll();
    }

    #[Test]
    public function it_subscribes_to_kernel_terminate_event(): void
    {
        $events = $this->subscriber::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::TERMINATE, $events);
    }

    #[Test]
    public function it_subscribes_with_low_priority(): void
    {
        $events = $this->subscriber::getSubscribedEvents();

        $terminateConfig = $events[KernelEvents::TERMINATE];

        self::assertIsArray($terminateConfig);
        self::assertSame('onKernelTerminate', $terminateConfig[0]);
        self::assertSame(-2048, $terminateConfig[1]);
    }

    #[Test]
    public function it_does_not_subscribe_to_kernel_request(): void
    {
        $events = $this->subscriber::getSubscribedEvents();

        self::assertArrayNotHasKey(KernelEvents::REQUEST, $events);
    }

    #[Test]
    public function it_clears_service_holder_on_terminate(): void
    {
        $token = 'test_token_123';
        ServiceHolder::store($token, $this->createServiceHolderData());

        self::assertNotNull(ServiceHolder::get($token));

        $this->subscriber->onKernelTerminate();

        self::assertNull(ServiceHolder::get($token));
    }

    #[Test]
    public function it_handles_empty_service_holder_gracefully(): void
    {
        self::assertNull(ServiceHolder::get('any_token'));

        $this->subscriber->onKernelTerminate();

        self::assertNull(ServiceHolder::get('any_token'));
    }

    #[Test]
    public function it_clears_multiple_tokens(): void
    {
        $serviceData = $this->createServiceHolderData();
        ServiceHolder::store('token_1', $serviceData);
        ServiceHolder::store('token_2', $serviceData);
        ServiceHolder::store('token_3', $serviceData);

        self::assertNotNull(ServiceHolder::get('token_1'));
        self::assertNotNull(ServiceHolder::get('token_2'));
        self::assertNotNull(ServiceHolder::get('token_3'));

        $this->subscriber->onKernelTerminate();

        self::assertNull(ServiceHolder::get('token_1'));
        self::assertNull(ServiceHolder::get('token_2'));
        self::assertNull(ServiceHolder::get('token_3'));
    }

    private function createServiceHolderData(): ServiceHolderData
    {
        $logger = new NullLogger();

        return new ServiceHolderData(
            analyzers: [],
            stopwatch: null,
            issueReconstructor: new IssueReconstructor(),
            queryStatsCalculator: new QueryStatsCalculator(),
            dataCollectorLogger: new DataCollectorLogger($logger),
            issueDeduplicator: new IssueDeduplicator(),
        );
    }
}
