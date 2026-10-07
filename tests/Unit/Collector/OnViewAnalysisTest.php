<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Collector;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collector\AnalysisTiming;
use AhmedBhs\DoctrineDoctor\Collector\DataCollectorHelpers;
use AhmedBhs\DoctrineDoctor\Collector\DoctrineDoctorDataCollector;
use AhmedBhs\DoctrineDoctor\Collector\Helper\DatabaseInfoCollector;
use AhmedBhs\DoctrineDoctor\Collector\Helper\DataCollectorLogger;
use AhmedBhs\DoctrineDoctor\Collector\Helper\IssueReconstructor;
use AhmedBhs\DoctrineDoctor\Collector\Helper\QueryStatsCalculator;
use AhmedBhs\DoctrineDoctor\Service\IssueDeduplicator;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Collector\RecordingAnalyzer;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AnalysisTiming::OnView: the profiled request only stores its queries, the
 * analysis runs later on the live collector for the stored (unserialized) one.
 */
final class OnViewAnalysisTest extends TestCase
{
    private const string SQL = 'SELECT u.id FROM users u WHERE u.id = ?';

    #[Test]
    public function collect_only_stores_the_queries_and_flags_the_analysis_as_pending(): void
    {
        $analyzer = $this->createRecordingAnalyzer();
        $collector = $this->createDataCollector(AnalysisTiming::OnView, [$analyzer]);

        $collector->collect(new Request(), new Response());

        self::assertTrue($collector->isAnalysisPending());
        self::assertSame(0, $analyzer->calls);
        self::assertCount(1, iterator_to_array($collector->getTimelineQueries(), false));
    }

    #[Test]
    public function late_collect_does_not_analyze_a_pending_profile(): void
    {
        $analyzer = $this->createRecordingAnalyzer();
        $collector = $this->createDataCollector(AnalysisTiming::OnView, [$analyzer]);

        $collector->collect(new Request(), new Response());
        $collector->lateCollect();

        self::assertTrue($collector->isAnalysisPending());
        self::assertSame(0, $analyzer->calls);
    }

    #[Test]
    public function the_live_collector_completes_the_analysis_of_a_stored_profile(): void
    {
        $profiled = $this->createDataCollector(AnalysisTiming::OnView);
        $profiled->collect(new Request(), new Response());
        $stored = $this->storeAndReload($profiled);

        $analyzer = $this->createRecordingAnalyzer(issue: true);
        $live = $this->createDataCollector(AnalysisTiming::OnView, [$analyzer]);

        self::assertTrue($live->completePendingAnalysis($stored));

        self::assertFalse($stored->isAnalysisPending());
        self::assertSame([self::SQL], $analyzer->sqls, 'Analyzers must run on the queries of the stored profile.');
        self::assertCount(1, $stored->getIssues());
        self::assertSame(1, $stored->getStats()['total_issues']);
    }

    #[Test]
    public function the_live_collector_keeps_no_state_from_a_completed_analysis(): void
    {
        $profiled = $this->createDataCollector(AnalysisTiming::OnView);
        $profiled->collect(new Request(), new Response());
        $stored = $this->storeAndReload($profiled);

        $live = $this->createDataCollector(AnalysisTiming::OnView, [$this->createRecordingAnalyzer(issue: true)]);
        $live->completePendingAnalysis($stored);

        self::assertSame([], $live->getIssues());
        self::assertFalse($live->isAnalysisPending());
    }

    #[Test]
    public function it_does_not_analyze_a_profile_that_is_not_pending(): void
    {
        $profiled = $this->createDataCollector(AnalysisTiming::Request);
        $profiled->collect(new Request(), new Response());
        $stored = $this->storeAndReload($profiled);

        $analyzer = $this->createRecordingAnalyzer();
        $live = $this->createDataCollector(AnalysisTiming::OnView, [$analyzer]);

        self::assertFalse($live->completePendingAnalysis($stored));
        self::assertSame(0, $analyzer->calls);
    }

    #[Test]
    public function late_collect_is_a_no_op_on_a_collector_loaded_from_the_storage(): void
    {
        $profiled = $this->createDataCollector(AnalysisTiming::AfterResponse);
        $profiled->collect(new Request(), new Response());
        $stored = $this->storeAndReload($profiled);

        // Profiler::saveProfile() calls lateCollect() on stored collectors: it must not fail
        $stored->lateCollect();

        self::assertFalse(isset($this->readData($stored)['stats']));
    }

    #[Test]
    public function a_collector_disabled_at_runtime_neither_stores_nor_analyzes_anything(): void
    {
        foreach ([AnalysisTiming::Request, AnalysisTiming::AfterResponse, AnalysisTiming::OnView] as $timing) {
            $analyzer = $this->createRecordingAnalyzer(issue: true);
            $collector = $this->createDataCollector($timing, [$analyzer], enabled: false);

            $collector->collect(new Request(), new Response());
            $collector->lateCollect();

            self::assertSame(0, $analyzer->calls, $timing->value);
            self::assertFalse($collector->isAnalysisPending(), $timing->value);
            self::assertSame([], iterator_to_array($collector->getTimelineQueries(), false), $timing->value);
            self::assertSame([], $collector->getIssues(), $timing->value);
        }
    }

    #[Test]
    public function an_explicit_timing_takes_precedence_over_the_legacy_deferral_flag(): void
    {
        $collector = $this->createDataCollector(AnalysisTiming::OnView, deferAnalysisToLateCollect: false);

        $collector->collect(new Request(), new Response());

        self::assertTrue($collector->isAnalysisPending());
    }

    #[Test]
    public function auto_timing_analyzes_during_the_request_on_the_cli(): void
    {
        self::assertSame(AnalysisTiming::Request, AnalysisTiming::fromEnvironment('cli', false));
        self::assertSame(AnalysisTiming::Request, AnalysisTiming::fromEnvironment('cli', true));
    }

    #[Test]
    public function auto_timing_analyzes_after_the_response_on_php_fpm(): void
    {
        self::assertSame(AnalysisTiming::AfterResponse, AnalysisTiming::fromEnvironment('fpm-fcgi', true));
    }

    #[Test]
    public function auto_timing_analyzes_on_view_when_the_response_cannot_be_flushed_early(): void
    {
        self::assertSame(AnalysisTiming::OnView, AnalysisTiming::fromEnvironment('apache2handler', false));
        self::assertSame(AnalysisTiming::OnView, AnalysisTiming::fromEnvironment('frankenphp', false));
    }

    #[Test]
    public function an_explicit_timing_resolves_to_itself(): void
    {
        foreach ([AnalysisTiming::Request, AnalysisTiming::AfterResponse, AnalysisTiming::OnView] as $timing) {
            self::assertSame($timing, $timing->resolve());
        }
    }

    private function storeAndReload(DoctrineDoctorDataCollector $collector): DoctrineDoctorDataCollector
    {
        $reloaded = unserialize(serialize($collector));
        self::assertInstanceOf(DoctrineDoctorDataCollector::class, $reloaded);

        return $reloaded;
    }

    /**
     * @return array<string, mixed>
     */
    private function readData(DoctrineDoctorDataCollector $collector): array
    {
        /** @var array<string, mixed> $data */
        $data = new \ReflectionProperty(DoctrineDoctorDataCollector::class, 'data')->getValue($collector);

        return $data;
    }

    private function createRecordingAnalyzer(bool $issue = false): RecordingAnalyzer
    {
        return new RecordingAnalyzer($issue);
    }

    /**
     * @param iterable<AnalyzerInterface> $analyzers
     */
    private function createDataCollector(
        AnalysisTiming $timing,
        iterable $analyzers = [],
        ?bool $deferAnalysisToLateCollect = null,
        bool $enabled = true,
    ): DoctrineDoctorDataCollector {
        $logger = new NullLogger();
        $helpers = new DataCollectorHelpers(
            databaseInfoCollector: new DatabaseInfoCollector(logger: $logger),
            issueReconstructor: new IssueReconstructor(),
            queryStatsCalculator: new QueryStatsCalculator(),
            dataCollectorLogger: new DataCollectorLogger(logger: $logger),
            issueDeduplicator: new IssueDeduplicator(),
        );

        $doctrineDataCollector = self::createStub(DoctrineDataCollector::class);
        $doctrineDataCollector->method('getQueries')->willReturn([
            'default' => [['sql' => self::SQL, 'params' => [1], 'types' => [], 'executionMS' => 0.001]],
        ]);

        return new DoctrineDoctorDataCollector(
            analyzers: $analyzers,
            doctrineDataCollector: $doctrineDataCollector,
            entityManager: null,
            stopwatch: null,
            showDebugInfo: false,
            dataCollectorHelpers: $helpers,
            excludePaths: ['vendor/'],
            deferAnalysisToLateCollect: $deferAnalysisToLateCollect,
            analysisTiming: $timing,
            enabled: $enabled,
        );
    }
}
