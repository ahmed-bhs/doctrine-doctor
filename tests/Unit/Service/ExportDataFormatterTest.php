<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Service;

use AhmedBhs\DoctrineDoctor\Collector\DoctrineDoctorDataCollector;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\Service\ExportDataFormatter;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExportDataFormatter::class)]
final class ExportDataFormatterTest extends TestCase
{
    private ExportDataFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ExportDataFormatter();
    }

    #[Test]
    public function it_formats_complete_export_payload_with_all_required_keys(): void
    {
        $collector = $this->createMock(DoctrineDoctorDataCollector::class);
        $collector->method('getIssues')->willReturn([]);
        $collector->method('getStats')->willReturn(['total' => 0]);
        $collector->method('getGroupedQueriesByTime')->willReturn([]);

        $export = $this->formatter->format($collector);

        self::assertArrayHasKey('created', $export);
        self::assertArrayHasKey('issues', $export);
        self::assertArrayHasKey('stats', $export);
        self::assertArrayHasKey('queries', $export);
    }

    #[Test]
    public function it_includes_iso_formatted_timestamp(): void
    {
        $collector = $this->createMock(DoctrineDoctorDataCollector::class);
        $collector->method('getIssues')->willReturn([]);
        $collector->method('getStats')->willReturn([]);
        $collector->method('getGroupedQueriesByTime')->willReturn([]);

        $export = $this->formatter->format($collector);

        self::assertIsString($export['created']);
        // Verify it's a valid ISO-8601 datetime string
        $dateTime = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $export['created']);
        self::assertInstanceOf(DateTimeImmutable::class, $dateTime);
    }

    #[Test]
    public function it_includes_stats_from_collector(): void
    {
        $stats = ['total' => 5, 'critical' => 1];
        $collector = $this->createMock(DoctrineDoctorDataCollector::class);
        $collector->method('getIssues')->willReturn([]);
        $collector->method('getStats')->willReturn($stats);
        $collector->method('getGroupedQueriesByTime')->willReturn([]);

        $export = $this->formatter->format($collector);

        self::assertSame($stats, $export['stats']);
    }

    #[Test]
    public function it_formats_issues_array(): void
    {
        $issueData = new IssueData(
            type: IssueType::N_PLUS_ONE->value,
            title: 'N+1 Query',
            description: 'Found N+1 pattern',
            severity: Severity::CRITICAL
        );
        $issue = new PerformanceIssue($issueData->toArray());

        $collector = $this->createMock(DoctrineDoctorDataCollector::class);
        $collector->method('getIssues')->willReturn([$issue]);
        $collector->method('getStats')->willReturn([]);
        $collector->method('getGroupedQueriesByTime')->willReturn([]);

        $export = $this->formatter->format($collector);

        self::assertCount(1, $export['issues']);
        self::assertIsArray($export['issues'][0]);
        self::assertArrayHasKey('title', $export['issues'][0]);
        self::assertSame('N+1 Query', $export['issues'][0]['title']);
    }

    #[Test]
    public function it_handles_empty_issues(): void
    {
        $collector = $this->createMock(DoctrineDoctorDataCollector::class);
        $collector->method('getIssues')->willReturn([]);
        $collector->method('getStats')->willReturn([]);
        $collector->method('getGroupedQueriesByTime')->willReturn([]);

        $export = $this->formatter->format($collector);

        self::assertSame([], $export['issues']);
    }

    #[Test]
    public function it_formats_multiple_issues(): void
    {
        $issue1 = new PerformanceIssue(new IssueData(
            type: IssueType::N_PLUS_ONE->value,
            title: 'N+1 #1',
            description: 'First N+1',
            severity: Severity::CRITICAL
        )->toArray());
        $issue2 = new PerformanceIssue(new IssueData(
            type: IssueType::N_PLUS_ONE->value,
            title: 'N+1 #2',
            description: 'Second N+1',
            severity: Severity::CRITICAL
        )->toArray());

        $collector = $this->createMock(DoctrineDoctorDataCollector::class);
        $collector->method('getIssues')->willReturn([$issue1, $issue2]);
        $collector->method('getStats')->willReturn([]);
        $collector->method('getGroupedQueriesByTime')->willReturn([]);

        $export = $this->formatter->format($collector);

        self::assertCount(2, $export['issues']);
    }

    #[Test]
    public function it_formats_queries_array(): void
    {
        $queries = [
            [
                'sql' => 'SELECT * FROM users',
                'count' => 5,
                'totalTimeMs' => 50.0,
                'avgTimeMs' => 10.0,
                'maxTimeMs' => 15.0,
                'minTimeMs' => 5.0,
                'backtrace' => [],
                'connection' => new \stdClass(),
            ],
        ];

        $collector = $this->createMock(DoctrineDoctorDataCollector::class);
        $collector->method('getIssues')->willReturn([]);
        $collector->method('getStats')->willReturn([]);
        $collector->method('getGroupedQueriesByTime')->willReturn($queries);

        $export = $this->formatter->format($collector);

        self::assertCount(1, $export['queries']);
        $query = $export['queries'][0];
        self::assertSame('SELECT * FROM users', $query['sql']);
        self::assertSame(5, $query['count']);
        self::assertSame(50.0, $query['totalTimeMs']);
        self::assertSame(10.0, $query['avgTimeMs']);
        self::assertSame(15.0, $query['maxTimeMs']);
        self::assertSame(5.0, $query['minTimeMs']);
    }

    #[Test]
    public function it_filters_non_serializable_fields_from_queries(): void
    {
        $queries = [
            [
                'sql' => 'SELECT * FROM users',
                'count' => 1,
                'totalTimeMs' => 10.0,
                'avgTimeMs' => 10.0,
                'maxTimeMs' => 10.0,
                'minTimeMs' => 10.0,
                'backtrace' => [['file' => 'test.php']],
                'connection' => new \stdClass(),
                'unexpected_field' => 'should be filtered',
            ],
        ];

        $collector = $this->createMock(DoctrineDoctorDataCollector::class);
        $collector->method('getIssues')->willReturn([]);
        $collector->method('getStats')->willReturn([]);
        $collector->method('getGroupedQueriesByTime')->willReturn($queries);

        $export = $this->formatter->format($collector);

        $query = $export['queries'][0];
        self::assertArrayNotHasKey('backtrace', $query);
        self::assertArrayNotHasKey('connection', $query);
        self::assertArrayNotHasKey('unexpected_field', $query);
    }

    #[Test]
    public function it_handles_empty_queries(): void
    {
        $collector = $this->createMock(DoctrineDoctorDataCollector::class);
        $collector->method('getIssues')->willReturn([]);
        $collector->method('getStats')->willReturn([]);
        $collector->method('getGroupedQueriesByTime')->willReturn([]);

        $export = $this->formatter->format($collector);

        self::assertSame([], $export['queries']);
    }

    #[Test]
    public function it_formats_multiple_queries(): void
    {
        $queries = [
            [
                'sql' => 'SELECT * FROM users',
                'count' => 1,
                'totalTimeMs' => 10.0,
                'avgTimeMs' => 10.0,
                'maxTimeMs' => 10.0,
                'minTimeMs' => 10.0,
            ],
            [
                'sql' => 'SELECT * FROM posts',
                'count' => 3,
                'totalTimeMs' => 30.0,
                'avgTimeMs' => 10.0,
                'maxTimeMs' => 12.0,
                'minTimeMs' => 8.0,
            ],
        ];

        $collector = $this->createMock(DoctrineDoctorDataCollector::class);
        $collector->method('getIssues')->willReturn([]);
        $collector->method('getStats')->willReturn([]);
        $collector->method('getGroupedQueriesByTime')->willReturn($queries);

        $export = $this->formatter->format($collector);

        self::assertCount(2, $export['queries']);
        self::assertSame('SELECT * FROM users', $export['queries'][0]['sql']);
        self::assertSame('SELECT * FROM posts', $export['queries'][1]['sql']);
    }

    #[Test]
    public function it_handles_zero_query_timing(): void
    {
        $queries = [
            [
                'sql' => 'SELECT 1',
                'count' => 1,
                'totalTimeMs' => 0.0,
                'avgTimeMs' => 0.0,
                'maxTimeMs' => 0.0,
                'minTimeMs' => 0.0,
            ],
        ];

        $collector = $this->createMock(DoctrineDoctorDataCollector::class);
        $collector->method('getIssues')->willReturn([]);
        $collector->method('getStats')->willReturn([]);
        $collector->method('getGroupedQueriesByTime')->willReturn($queries);

        $export = $this->formatter->format($collector);

        $query = $export['queries'][0];
        self::assertSame(0.0, $query['totalTimeMs']);
        self::assertSame(0.0, $query['avgTimeMs']);
    }

    #[Test]
    public function it_formats_issues_via_public_method(): void
    {
        $issue = new PerformanceIssue(new IssueData(
            type: IssueType::SLOW_QUERY->value,
            title: 'Slow Query',
            description: 'Query took too long',
            severity: Severity::CRITICAL,
        )->toArray());

        $formatted = $this->formatter->formatIssues([$issue]);

        self::assertCount(1, $formatted);
        self::assertIsArray($formatted[0]);
        self::assertArrayHasKey('title', $formatted[0]);
    }

    #[Test]
    public function it_formats_queries_via_public_method(): void
    {
        $queries = [
            [
                'sql' => 'SELECT * FROM users',
                'count' => 2,
                'totalTimeMs' => 20.0,
                'avgTimeMs' => 10.0,
                'maxTimeMs' => 12.0,
                'minTimeMs' => 8.0,
            ],
        ];

        $formatted = $this->formatter->formatQueries($queries);

        self::assertCount(1, $formatted);
        self::assertSame('SELECT * FROM users', $formatted[0]['sql']);
        self::assertSame(2, $formatted[0]['count']);
    }

    #[Test]
    public function it_preserves_floating_point_precision_in_queries(): void
    {
        $queries = [
            [
                'sql' => 'SELECT 1',
                'count' => 1,
                'totalTimeMs' => 123.456,
                'avgTimeMs' => 123.456,
                'maxTimeMs' => 123.456,
                'minTimeMs' => 123.456,
            ],
        ];

        $collector = $this->createMock(DoctrineDoctorDataCollector::class);
        $collector->method('getIssues')->willReturn([]);
        $collector->method('getStats')->willReturn([]);
        $collector->method('getGroupedQueriesByTime')->willReturn($queries);

        $export = $this->formatter->format($collector);

        $query = $export['queries'][0];
        self::assertSame(123.456, $query['totalTimeMs']);
        self::assertSame(123.456, $query['avgTimeMs']);
    }
}
