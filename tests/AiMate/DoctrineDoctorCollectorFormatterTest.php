<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\AiMate;

use AhmedBhs\DoctrineDoctor\AiMate\DoctrineDoctorMcpSanitizer;
use AhmedBhs\DoctrineDoctor\AiMate\Formatter\DoctrineDoctorCollectorFormatter;
use AhmedBhs\DoctrineDoctor\AiMate\TraceSanitizer;
use AhmedBhs\DoctrineDoctor\Collector\DoctrineDoctorDataCollector;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\DataCollector\DataCollectorInterface;

final class DoctrineDoctorCollectorFormatterTest extends TestCase
{
    private DoctrineDoctorCollectorFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new DoctrineDoctorCollectorFormatter(
            new DoctrineDoctorMcpSanitizer(new TraceSanitizer('/app')),
        );
    }

    #[Test]
    public function it_is_named_after_the_doctrine_doctor_collector(): void
    {
        self::assertSame('doctrine_doctor', $this->formatter->getName());
    }

    #[Test]
    public function it_formats_stats_database_info_overhead_and_sanitized_issues(): void
    {
        $collector = $this->createCollector(
            stats: ['total' => 1],
            databaseInfo: ['platform' => 'sqlite'],
            profilerOverhead: ['analysis_time_ms' => 1.5, 'db_info_time_ms' => 0.2, 'total_time_ms' => 1.7],
            issues: [
                new PerformanceIssue([
                    'type' => 'slow_query',
                    'title' => 'Slow query',
                    'description' => 'A slow query was detected.',
                    'severity' => Severity::critical(),
                ]),
            ],
        );

        $payload = $this->formatter->format($collector);

        self::assertSame(['total' => 1], $payload['stats']);
        self::assertSame(['platform' => 'sqlite'], $payload['database_info']);
        self::assertSame(['analysis_time_ms' => 1.5, 'db_info_time_ms' => 0.2, 'total_time_ms' => 1.7], $payload['profiler_overhead']);
        self::assertCount(1, $payload['issues']);
        self::assertSame('slow_query', $payload['issues'][0]['type']);
    }

    #[Test]
    public function it_returns_an_error_when_given_a_foreign_collector(): void
    {
        $collector = self::createStub(DataCollectorInterface::class);

        self::assertSame(['error' => 'Invalid doctrine_doctor collector'], $this->formatter->format($collector));
        self::assertSame(['error' => 'Invalid doctrine_doctor collector'], $this->formatter->getSummary($collector));
    }

    #[Test]
    public function it_summarizes_using_the_collector_stats(): void
    {
        $collector = $this->createCollector(stats: ['total' => 3, 'critical' => 1]);

        self::assertSame(['total' => 3, 'critical' => 1], $this->formatter->getSummary($collector));
    }

    /**
     * @param array<string, mixed>                                                         $stats
     * @param array<string, mixed>                                                         $databaseInfo
     * @param array{analysis_time_ms: float, db_info_time_ms: float, total_time_ms: float} $profilerOverhead
     * @param array<int, PerformanceIssue>                                                 $issues
     */
    private function createCollector(
        array $stats = [],
        array $databaseInfo = [],
        array $profilerOverhead = ['analysis_time_ms' => 0.0, 'db_info_time_ms' => 0.0, 'total_time_ms' => 0.0],
        array $issues = [],
    ): DoctrineDoctorDataCollector {
        return new class($stats, $databaseInfo, $profilerOverhead, $issues) extends DoctrineDoctorDataCollector {
            /**
             * @param array<string, mixed>                                                              $stats
             * @param array<string, mixed>                                                              $databaseInfo
             * @param array{analysis_time_ms: float, db_info_time_ms: float, total_time_ms: float}      $profilerOverhead
             * @param array<int, PerformanceIssue>                                                      $issues
             */
            public function __construct(
                private readonly array $stats,
                private readonly array $databaseInfo,
                private readonly array $profilerOverhead,
                private readonly array $issues,
            ) {
            }

            public function getStats(): array
            {
                return $this->stats;
            }

            public function getDatabaseInfo(): array
            {
                return $this->databaseInfo;
            }

            public function getProfilerOverhead(): array
            {
                return $this->profilerOverhead;
            }

            public function getIssues(): array
            {
                return $this->issues;
            }
        };
    }
}
