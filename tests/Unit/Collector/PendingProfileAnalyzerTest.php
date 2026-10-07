<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Collector;

use AhmedBhs\DoctrineDoctor\Collector\AnalysisResultStore;
use AhmedBhs\DoctrineDoctor\Collector\AnalysisTiming;
use AhmedBhs\DoctrineDoctor\Collector\DataCollectorHelpers;
use AhmedBhs\DoctrineDoctor\Collector\DoctrineDoctorDataCollector;
use AhmedBhs\DoctrineDoctor\Collector\Helper\DatabaseInfoCollector;
use AhmedBhs\DoctrineDoctor\Collector\Helper\DataCollectorLogger;
use AhmedBhs\DoctrineDoctor\Collector\Helper\IssueReconstructor;
use AhmedBhs\DoctrineDoctor\Collector\Helper\QueryStatsCalculator;
use AhmedBhs\DoctrineDoctor\Collector\PendingProfileAnalyzer;
use AhmedBhs\DoctrineDoctor\Command\AnalyzeProfileCommand;
use AhmedBhs\DoctrineDoctor\EventSubscriber\PendingAnalysisSubscriber;
use AhmedBhs\DoctrineDoctor\Service\IssueDeduplicator;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Collector\ReserializationGuardCollector;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;

final class PendingProfileAnalyzerTest extends TestCase
{
    private FileProfilerStorage $storage;

    private string $resultDirectory;

    protected function setUp(): void
    {
        $this->storage = new FileProfilerStorage('file:' . sys_get_temp_dir() . '/dd-pending-' . uniqid('', true));
        $this->resultDirectory = sys_get_temp_dir() . '/dd-results-' . uniqid('', true);
        AnalysisResultStore::useAsDefault(new AnalysisResultStore($this->resultDirectory));
    }

    protected function tearDown(): void
    {
        AnalysisResultStore::useAsDefault(null);
        $this->storage->purge();
        new Filesystem()->remove($this->resultDirectory);
    }

    #[Test]
    public function it_analyzes_a_pending_profile_and_stores_the_result(): void
    {
        $this->storePendingProfile('tok123');

        self::assertTrue($this->createAnalyzer()->analyze('tok123'));

        // A fresh copy loaded from the storage, as the profiler controller does
        $collector = $this->readCollector('tok123');
        self::assertFalse($collector->isAnalysisPending());
        self::assertArrayHasKey('total_issues', $collector->getStats());
    }

    #[Test]
    public function it_never_serializes_the_stored_profile_again(): void
    {
        // Symfony's FormDataCollector fails when a loaded profile is serialized again
        $this->storePendingProfile('tok123', new ReserializationGuardCollector());

        self::assertTrue($this->createAnalyzer()->analyze('tok123'));
        self::assertFalse($this->readCollector('tok123')->isAnalysisPending());
    }

    #[Test]
    public function it_analyzes_a_profile_only_once(): void
    {
        $this->storePendingProfile('tok123');
        $analyzer = $this->createAnalyzer();

        self::assertTrue($analyzer->analyze('tok123'));
        self::assertFalse($analyzer->analyze('tok123'));
    }

    #[Test]
    public function the_profile_stays_pending_without_a_result_store(): void
    {
        $this->storePendingProfile('tok123');
        AnalysisResultStore::useAsDefault(null);

        self::assertTrue($this->readCollector('tok123')->isAnalysisPending());
    }

    #[Test]
    public function it_does_nothing_for_an_already_analyzed_profile(): void
    {
        $this->storeProfile('tok123', $this->collect(AnalysisTiming::Request));

        self::assertFalse($this->createAnalyzer()->analyze('tok123'));
    }

    #[Test]
    public function it_does_nothing_for_an_unknown_token(): void
    {
        self::assertFalse($this->createAnalyzer()->analyze('missing'));
    }

    #[Test]
    public function it_does_nothing_without_a_profiler_storage(): void
    {
        self::assertFalse(new PendingProfileAnalyzer(
            $this->createCollector(AnalysisTiming::OnView),
            null,
            new AnalysisResultStore($this->resultDirectory),
        )->analyze('tok123'));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function profilerRequests(): iterable
    {
        yield 'web debug toolbar' => ['_wdt', 'tok123', true];
        yield 'profiler panel' => ['_profiler', 'tok123', true];
        yield 'other route' => ['app_home', 'tok123', false];
        yield 'latest profile' => ['_profiler', 'latest', false];
    }

    #[Test]
    #[DataProvider('profilerRequests')]
    public function the_subscriber_analyzes_profiles_opened_in_the_toolbar_or_the_profiler(string $route, string $token, bool $analyzed): void
    {
        $this->storePendingProfile('tok123');

        $request = new Request();
        $request->attributes->set('_route', $route);
        $request->attributes->set('token', $token);

        new PendingAnalysisSubscriber(fn (): PendingProfileAnalyzer => $this->createAnalyzer())->onKernelRequest(
            new RequestEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST),
        );

        self::assertSame(!$analyzed, $this->readCollector('tok123')->isAnalysisPending());
    }

    #[Test]
    public function the_subscriber_builds_nothing_outside_the_profiler_routes(): void
    {
        // kernel.request listeners are instantiated before the firewall runs: building the
        // analyzer (profiler, every collector, Twig and its globals) there would construct
        // services that read the user before authentication
        $subscriber = new PendingAnalysisSubscriber(static function (): PendingProfileAnalyzer {
            throw new \LogicException('The analyzer must not be built for this request');
        });

        $request = new Request();
        $request->attributes->set('_route', 'app_home');
        $request->attributes->set('token', 'tok123');

        $subscriber->onKernelRequest(new RequestEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_command_analyzes_the_given_profiles(): void
    {
        $this->storePendingProfile('tok123');
        $tester = new CommandTester(new AnalyzeProfileCommand($this->createAnalyzer()));

        $tester->execute(['tokens' => ['tok123', 'missing']]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('tok123: analyzed', $tester->getDisplay());
        self::assertStringContainsString('missing: nothing pending', $tester->getDisplay());
        self::assertFalse($this->readCollector('tok123')->isAnalysisPending());
    }

    private function createAnalyzer(): PendingProfileAnalyzer
    {
        return new PendingProfileAnalyzer(
            $this->createCollector(AnalysisTiming::OnView),
            new Profiler($this->storage),
            new AnalysisResultStore($this->resultDirectory),
        );
    }

    private function storePendingProfile(string $token, ?DataCollector $other = null): void
    {
        $this->storeProfile($token, $this->collect(AnalysisTiming::OnView), $other);
    }

    private function collect(AnalysisTiming $timing): DoctrineDoctorDataCollector
    {
        $collector = $this->createCollector($timing);
        $collector->collect(new Request(), new Response());

        return $collector;
    }

    private function storeProfile(string $token, DoctrineDoctorDataCollector $collector, ?DataCollector $other = null): void
    {
        $profile = new Profile($token);
        $profile->setMethod('GET');
        $profile->setUrl('http://localhost/');
        $profile->setStatusCode(200);
        $profile->setIp('127.0.0.1');
        $profile->setTime(time());
        $profile->addCollector($collector);

        if (null !== $other) {
            $profile->addCollector($other);
        }

        $this->storage->write($profile);
    }

    private function readCollector(string $token): DoctrineDoctorDataCollector
    {
        $collector = $this->storage->read($token)?->getCollector('doctrine_doctor');
        self::assertInstanceOf(DoctrineDoctorDataCollector::class, $collector);

        return $collector;
    }

    private function createCollector(AnalysisTiming $timing): DoctrineDoctorDataCollector
    {
        $logger = new NullLogger();
        $doctrineDataCollector = self::createStub(DoctrineDataCollector::class);
        $doctrineDataCollector->method('getQueries')->willReturn([
            'default' => [['sql' => 'SELECT id FROM users', 'params' => [], 'types' => [], 'executionMS' => 0.001]],
        ]);

        return new DoctrineDoctorDataCollector(
            analyzers: [],
            doctrineDataCollector: $doctrineDataCollector,
            entityManager: null,
            stopwatch: null,
            showDebugInfo: false,
            dataCollectorHelpers: new DataCollectorHelpers(
                databaseInfoCollector: new DatabaseInfoCollector(logger: $logger),
                issueReconstructor: new IssueReconstructor(),
                queryStatsCalculator: new QueryStatsCalculator(),
                dataCollectorLogger: new DataCollectorLogger(logger: $logger),
                issueDeduplicator: new IssueDeduplicator(),
            ),
            analysisTiming: $timing,
        );
    }
}
