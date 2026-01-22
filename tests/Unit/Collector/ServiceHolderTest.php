<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Collector;

use AhmedBhs\DoctrineDoctor\Collector\Helper\DataCollectorLogger;
use AhmedBhs\DoctrineDoctor\Collector\Helper\IssueReconstructor;
use AhmedBhs\DoctrineDoctor\Collector\Helper\QueryStatsCalculator;
use AhmedBhs\DoctrineDoctor\Collector\ServiceHolder;
use AhmedBhs\DoctrineDoctor\Collector\ServiceHolderData;
use AhmedBhs\DoctrineDoctor\Service\IssueDeduplicator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Webmozart\Assert\InvalidArgumentException;

/**
 * Unit tests for ServiceHolder.
 */
final class ServiceHolderTest extends TestCase
{
    protected function setUp(): void
    {
        ServiceHolder::clearAll();
    }

    protected function tearDown(): void
    {
        ServiceHolder::clearAll();
    }

    #[Test]
    public function it_stores_and_retrieves_service_data(): void
    {
        $token = 'test_token';
        $serviceData = $this->createServiceHolderData();

        ServiceHolder::store($token, $serviceData);

        self::assertSame($serviceData, ServiceHolder::get($token));
    }

    #[Test]
    public function it_returns_null_for_unknown_token(): void
    {
        self::assertNull(ServiceHolder::get('unknown_token'));
    }

    #[Test]
    public function it_clears_specific_token(): void
    {
        $serviceData = $this->createServiceHolderData();
        ServiceHolder::store('token_1', $serviceData);
        ServiceHolder::store('token_2', $serviceData);

        ServiceHolder::clear('token_1');

        self::assertNull(ServiceHolder::get('token_1'));
        self::assertNotNull(ServiceHolder::get('token_2'));
    }

    #[Test]
    public function it_clears_all_tokens(): void
    {
        $serviceData = $this->createServiceHolderData();
        ServiceHolder::store('token_1', $serviceData);
        ServiceHolder::store('token_2', $serviceData);
        ServiceHolder::store('token_3', $serviceData);

        ServiceHolder::clearAll();

        self::assertNull(ServiceHolder::get('token_1'));
        self::assertNull(ServiceHolder::get('token_2'));
        self::assertNull(ServiceHolder::get('token_3'));
    }

    #[Test]
    public function it_throws_exception_for_empty_token(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $serviceData = $this->createServiceHolderData();
        ServiceHolder::store('', $serviceData);
    }

    #[Test]
    public function it_overwrites_existing_token(): void
    {
        $serviceData1 = $this->createServiceHolderData();
        $serviceData2 = $this->createServiceHolderData();

        ServiceHolder::store('token', $serviceData1);
        ServiceHolder::store('token', $serviceData2);

        self::assertSame($serviceData2, ServiceHolder::get('token'));
    }

    #[Test]
    public function clear_is_idempotent(): void
    {
        $serviceData = $this->createServiceHolderData();
        ServiceHolder::store('token', $serviceData);

        // Clear multiple times should not throw
        ServiceHolder::clear('token');
        ServiceHolder::clear('token');
        ServiceHolder::clear('token');

        self::assertNull(ServiceHolder::get('token'));
    }

    #[Test]
    public function clear_all_is_idempotent(): void
    {
        // Clear on empty holder should not throw
        ServiceHolder::clearAll();
        ServiceHolder::clearAll();
        ServiceHolder::clearAll();

        self::assertNull(ServiceHolder::get('any'));
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
