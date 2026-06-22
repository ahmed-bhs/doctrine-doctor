<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Collector;

use AhmedBhs\DoctrineDoctor\Collector\DataCollectorHelpers;
use AhmedBhs\DoctrineDoctor\Collector\DoctrineDoctorDataCollector;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Whether analysis runs in collect() or lateCollect() is decided by the
 * injected $deferAnalysisToLateCollect flag (defaulting to
 * function_exists('fastcgi_finish_request') in the constructor, but tests
 * inject the value directly so the two branches are deterministic and need
 * no SAPI-specific extension).
 *
 * "Has analysis run yet" is observed via $data['stats'], which runAnalysis()
 * is the only thing that sets.
 */
final class DoctrineDoctorDataCollectorDeferralTest extends TestCase
{
    #[Test]
    public function it_runs_analysis_in_collect_when_deferral_is_disabled(): void
    {
        $collector = $this->createDataCollector(deferAnalysisToLateCollect: false);

        $collector->collect(new Request(), new Response());

        self::assertTrue(isset($this->readData($collector)['stats']), 'collect() must run analysis immediately when deferral is disabled (persistent runtimes).');
    }

    #[Test]
    public function it_does_not_run_analysis_in_collect_when_deferral_is_enabled(): void
    {
        $collector = $this->createDataCollector(deferAnalysisToLateCollect: true);

        $collector->collect(new Request(), new Response());

        self::assertFalse(isset($this->readData($collector)['stats']), 'collect() must not run analysis when deferral is enabled: it has to stay off the request critical path.');
    }

    #[Test]
    public function it_runs_analysis_in_late_collect_when_deferral_is_enabled(): void
    {
        $collector = $this->createDataCollector(deferAnalysisToLateCollect: true);

        $collector->collect(new Request(), new Response());
        $collector->lateCollect();

        self::assertTrue(isset($this->readData($collector)['stats']), 'lateCollect() must run the deferred analysis.');
    }

    #[Test]
    public function it_does_not_run_analysis_twice_when_deferral_is_disabled(): void
    {
        $collector = $this->createDataCollector(deferAnalysisToLateCollect: false);

        $collector->collect(new Request(), new Response());
        $statsAfterCollect = $this->readData($collector)['stats'];

        $collector->lateCollect();
        $statsAfterLateCollect = $this->readData($collector)['stats'];

        self::assertSame($statsAfterCollect, $statsAfterLateCollect, 'lateCollect() must be a no-op when analysis already ran in collect(): the two phases are mutually exclusive.');
    }

    /**
     * @return array<string, mixed>
     */
    private function readData(DoctrineDoctorDataCollector $collector): array
    {
        $reflection = new \ReflectionProperty(DoctrineDoctorDataCollector::class, 'data');

        /** @var array<string, mixed> $data */
        $data = $reflection->getValue($collector);

        return $data;
    }

    private function createDataCollector(bool $deferAnalysisToLateCollect): DoctrineDoctorDataCollector
    {
        $logger = new NullLogger();
        $helpers = new DataCollectorHelpers(
            databaseInfoCollector: new \AhmedBhs\DoctrineDoctor\Collector\Helper\DatabaseInfoCollector(
                logger: $logger,
            ),
            issueReconstructor: new \AhmedBhs\DoctrineDoctor\Collector\Helper\IssueReconstructor(),
            queryStatsCalculator: new \AhmedBhs\DoctrineDoctor\Collector\Helper\QueryStatsCalculator(),
            dataCollectorLogger: new \AhmedBhs\DoctrineDoctor\Collector\Helper\DataCollectorLogger(
                logger: $logger,
            ),
            issueDeduplicator: new \AhmedBhs\DoctrineDoctor\Service\IssueDeduplicator(),
        );

        // A real DoctrineDataCollector (instead of null) is required: collect()
        // returns immediately when it is absent, before reaching the deferral
        // check this test suite covers. getQueries() returning [] is enough.
        $doctrineDataCollector = self::createStub(DoctrineDataCollector::class);
        $doctrineDataCollector->method('getQueries')->willReturn([]);

        return new DoctrineDoctorDataCollector(
            analyzers: [],
            doctrineDataCollector: $doctrineDataCollector,
            entityManager: null,
            stopwatch: null,
            showDebugInfo: false,
            dataCollectorHelpers: $helpers,
            excludePaths: ['vendor/'],
            deferAnalysisToLateCollect: $deferAnalysisToLateCollect,
        );
    }
}
