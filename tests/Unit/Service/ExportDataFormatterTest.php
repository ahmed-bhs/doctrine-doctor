<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Service;

use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\Service\ExportDataFormatter;
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
        $export = $this->formatter->format([], ['total' => 0], []);

        self::assertArrayHasKey('created', $export);
        self::assertArrayHasKey('issues', $export);
        self::assertArrayHasKey('stats', $export);
        self::assertArrayHasKey('queries', $export);
    }

    #[Test]
    public function it_includes_iso_formatted_timestamp(): void
    {
        $export = $this->formatter->format([], [], []);

        $dateTime = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $export['created']);

        self::assertInstanceOf(DateTimeImmutable::class, $dateTime);
    }

    #[Test]
    public function it_includes_stats_unchanged(): void
    {
        $stats = ['total' => 5, 'critical' => 1];

        $export = $this->formatter->format([], $stats, []);

        self::assertSame($stats, $export['stats']);
    }

    #[Test]
    public function it_formats_issues_array(): void
    {
        $issue = $this->createIssue('N+1 Query', 'Found N+1 pattern');

        $export = $this->formatter->format([$issue], [], []);

        self::assertCount(1, $export['issues']);
        self::assertSame('N+1 Query', $export['issues'][0]['title']);
    }

    #[Test]
    public function it_handles_empty_issues(): void
    {
        $export = $this->formatter->format([], [], []);

        self::assertSame([], $export['issues']);
    }

    #[Test]
    public function it_formats_multiple_issues(): void
    {
        $issues = [
            $this->createIssue('N+1 #1', 'First N+1'),
            $this->createIssue('N+1 #2', 'Second N+1'),
        ];

        $export = $this->formatter->format($issues, [], []);

        self::assertCount(2, $export['issues']);
    }

    #[Test]
    public function it_formats_queries_array(): void
    {
        $export = $this->formatter->format([], [], [$this->createQuery()]);

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
    public function it_omits_non_serializable_query_fields(): void
    {
        $query = $this->createQuery();
        $query['firstQuery'] = [
            'backtrace' => [['file' => 'test.php']],
            'connection' => new \stdClass(),
        ];

        $export = $this->formatter->format([], [], [$query]);

        self::assertArrayNotHasKey('firstQuery', $export['queries'][0]);
        self::assertSame(
            ['sql', 'count', 'totalTimeMs', 'avgTimeMs', 'maxTimeMs', 'minTimeMs'],
            array_keys($export['queries'][0]),
        );
    }

    #[Test]
    public function it_produces_a_json_encodable_payload_when_queries_carry_objects(): void
    {
        $query = $this->createQuery();
        $query['firstQuery'] = ['connection' => new \stdClass()];

        $export = $this->formatter->format([$this->createIssue('N+1', 'desc')], ['total' => 1], [$query]);

        self::assertIsString(json_encode($export, \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_handles_empty_queries(): void
    {
        $export = $this->formatter->format([], [], []);

        self::assertSame([], $export['queries']);
    }

    #[Test]
    public function it_formats_multiple_queries(): void
    {
        $first = $this->createQuery();
        $second = $this->createQuery();
        $second['sql'] = 'SELECT * FROM posts';

        $export = $this->formatter->format([], [], [$first, $second]);

        self::assertCount(2, $export['queries']);
        self::assertSame('SELECT * FROM users', $export['queries'][0]['sql']);
        self::assertSame('SELECT * FROM posts', $export['queries'][1]['sql']);
    }

    #[Test]
    public function it_handles_zero_query_timing(): void
    {
        $query = $this->createQuery();
        $query['totalTimeMs'] = 0.0;
        $query['avgTimeMs'] = 0.0;

        $export = $this->formatter->format([], [], [$query]);

        self::assertSame(0.0, $export['queries'][0]['totalTimeMs']);
        self::assertSame(0.0, $export['queries'][0]['avgTimeMs']);
    }

    #[Test]
    public function it_preserves_floating_point_precision_in_queries(): void
    {
        $query = $this->createQuery();
        $query['totalTimeMs'] = 123.456;

        $export = $this->formatter->format([], [], [$query]);

        self::assertSame(123.456, $export['queries'][0]['totalTimeMs']);
    }

    #[Test]
    public function it_reindexes_sparse_issue_and_query_lists(): void
    {
        $issues = [3 => $this->createIssue('N+1', 'desc')];
        $queries = [7 => $this->createQuery()];

        $export = $this->formatter->format($issues, [], $queries);

        self::assertSame([0], array_keys($export['issues']));
        self::assertSame([0], array_keys($export['queries']));
    }

    private function createIssue(string $title, string $description): PerformanceIssue
    {
        return new PerformanceIssue(new IssueData(
            type: IssueType::N_PLUS_ONE->value,
            title: $title,
            description: $description,
            severity: Severity::CRITICAL,
        )->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    private function createQuery(): array
    {
        return [
            'sql' => 'SELECT * FROM users',
            'count' => 5,
            'totalTimeMs' => 50.0,
            'avgTimeMs' => 10.0,
            'maxTimeMs' => 15.0,
            'minTimeMs' => 5.0,
        ];
    }
}
