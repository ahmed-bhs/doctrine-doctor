<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\AiMate;

use AhmedBhs\DoctrineDoctor\AiMate\Capability\DoctrineDoctorIssuesTool;
use AhmedBhs\DoctrineDoctor\AiMate\DoctrineDoctorMcpSanitizer;
use AhmedBhs\DoctrineDoctor\AiMate\PendingAnalysisRunnerInterface;
use AhmedBhs\DoctrineDoctor\AiMate\TraceSanitizer;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collector\AnalysisResultStore;
use AhmedBhs\DoctrineDoctor\Collector\DoctrineDoctorDataCollector;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\AiMate\FixtureDoctrineDoctorCollector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\CollectorRegistry;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\ProfilerDataProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class DoctrineDoctorIssuesToolTest extends TestCase
{
    private string $profilerDir;

    public const string ANALYSIS_KEY = '0123456789abcdef0123456789abcdef';

    private FileProfilerStorage $storage;

    private AnalysisResultStore $resultStore;

    private string $resultDirectory;

    protected function setUp(): void
    {
        $this->profilerDir = sys_get_temp_dir() . '/dd-mate-tool-' . uniqid('', true);
        $this->storage = new FileProfilerStorage('file:' . $this->profilerDir);
        $this->resultDirectory = $this->profilerDir . '-results';
        $this->resultStore = new AnalysisResultStore($this->resultDirectory);
    }

    protected function tearDown(): void
    {
        $this->storage->purge();
        new Filesystem()->remove($this->resultDirectory);
    }

    #[Test]
    public function it_returns_stats_and_sanitized_issues_for_a_stored_profile(): void
    {
        $this->storeProfile('abc123', new FixtureDoctrineDoctorCollector());

        $result = $this->createTool()->getIssues(token: 'abc123');

        self::assertSame(['total' => 1, 'critical' => 1], $result['stats']);
        self::assertCount(1, $result['issues']);
        self::assertSame('slow_query', $result['issues'][0]['type']);
    }

    #[Test]
    public function it_uses_the_latest_profile_when_no_token_is_given(): void
    {
        $this->storeProfile('latest-token', new FixtureDoctrineDoctorCollector());

        $result = $this->createTool()->getIssues();

        self::assertCount(1, $result['issues']);
    }

    #[Test]
    public function it_reports_an_error_when_no_profiles_exist(): void
    {
        self::assertSame(['error' => 'No profiler profiles found'], $this->createTool()->getIssues());
    }

    #[Test]
    public function it_reports_an_error_for_an_unknown_token(): void
    {
        self::assertSame(
            ['error' => 'Profile not found for token: missing'],
            $this->createTool()->getIssues(token: 'missing'),
        );
    }

    #[Test]
    public function it_reports_an_error_when_the_doctrine_doctor_collector_is_absent(): void
    {
        $this->storeProfile('no-dd', collector: null);

        $result = $this->createTool()->getIssues(token: 'no-dd');

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('doctrine_doctor collector not found', $result['error']);
    }

    #[Test]
    public function it_reads_a_stored_result_without_running_the_analysis_again(): void
    {
        $this->storeProfile('pending', $this->pendingCollector());
        $this->resultStore->save(self::ANALYSIS_KEY, $this->analysisResult());

        $runner = new class() implements PendingAnalysisRunnerInterface {
            public function run(string $token): bool
            {
                throw new \LogicException('Must not be called');
            }
        };

        $result = $this->createTool($runner)->getIssues(token: 'pending');

        self::assertArrayNotHasKey('analysis_pending', $result);
        self::assertCount(1, $result['issues']);
        self::assertSame('slow_query', $result['issues'][0]['type']);
    }

    #[Test]
    public function it_runs_the_pending_analysis_before_reading_the_issues(): void
    {
        $this->storeProfile('pending', $this->pendingCollector());

        $runner = new class($this->resultStore, $this->analysisResult()) implements PendingAnalysisRunnerInterface {
            /** @var list<string> */
            public array $tokens = [];

            /**
             * @param array<string, mixed> $result
             */
            public function __construct(
                private readonly AnalysisResultStore $resultStore,
                private readonly array $result,
            ) {
            }

            public function run(string $token): bool
            {
                $this->tokens[] = $token;
                // The real runner analyzes the profile in the application and stores the result
                $this->resultStore->save(DoctrineDoctorIssuesToolTest::ANALYSIS_KEY, $this->result);

                return true;
            }
        };

        $result = $this->createTool($runner)->getIssues(token: 'pending');

        self::assertSame(['pending'], $runner->tokens);
        self::assertArrayNotHasKey('analysis_pending', $result);
        self::assertCount(1, $result['issues']);
        self::assertSame('slow_query', $result['issues'][0]['type']);
    }

    #[Test]
    public function it_reports_a_pending_analysis_it_could_not_run(): void
    {
        $this->storeProfile('pending', $this->pendingCollector());

        $runner = new class() implements PendingAnalysisRunnerInterface {
            public function run(string $token): bool
            {
                return false;
            }
        };

        $result = $this->createTool($runner)->getIssues(token: 'pending');

        self::assertTrue($result['analysis_pending']);
        self::assertStringContainsString('doctrine:doctor:analyze-profile pending', $result['hint']);
        self::assertSame([], $result['issues']);
    }

    #[Test]
    public function it_does_not_run_the_analysis_when_nothing_is_pending(): void
    {
        $this->storeProfile('abc123', new FixtureDoctrineDoctorCollector());

        $runner = new class() implements PendingAnalysisRunnerInterface {
            public function run(string $token): bool
            {
                throw new \LogicException('Must not be called');
            }
        };

        self::assertCount(1, $this->createTool($runner)->getIssues(token: 'abc123')['issues']);
    }

    /**
     * A collector as loaded from the profiler storage, collected with AnalysisTiming::OnView.
     */
    private function pendingCollector(): DoctrineDoctorDataCollector
    {
        $collector = new \ReflectionClass(DoctrineDoctorDataCollector::class)->newInstanceWithoutConstructor();
        new \ReflectionProperty(DoctrineDoctorDataCollector::class, 'data')->setValue($collector, [
            'enabled' => true,
            'analysis_pending' => true,
            'analysis_key' => self::ANALYSIS_KEY,
            'timeline_queries' => [],
        ]);

        return $collector;
    }

    /**
     * @return array<string, mixed>
     */
    private function analysisResult(): array
    {
        return [
            'issues' => IssueCollection::fromArray([new PerformanceIssue([
                'type' => 'slow_query',
                'title' => 'Slow query',
                'description' => 'A slow query was detected.',
                'severity' => 'critical',
                'queries' => [],
            ])])->toArrayOfArrays(),
            'stats' => ['total_issues' => 1, 'critical' => 1, 'warning' => 0, 'info' => 0, 'skipped_analyzers' => 0],
        ];
    }

    private function createTool(?PendingAnalysisRunnerInterface $runner = null): DoctrineDoctorIssuesTool
    {
        return new DoctrineDoctorIssuesTool(
            new ProfilerDataProvider($this->profilerDir, new CollectorRegistry()),
            new DoctrineDoctorMcpSanitizer(new TraceSanitizer('/app')),
            $runner,
            $this->resultStore,
        );
    }

    private function storeProfile(string $token, ?DoctrineDoctorDataCollector $collector): void
    {
        $profile = new Profile($token);
        $profile->setMethod('GET');
        $profile->setUrl('http://localhost/');
        $profile->setStatusCode(200);
        $profile->setIp('127.0.0.1');
        $profile->setTime(time());

        if (null !== $collector) {
            $profile->addCollector($collector);
        }

        $this->storage->write($profile);
    }
}
