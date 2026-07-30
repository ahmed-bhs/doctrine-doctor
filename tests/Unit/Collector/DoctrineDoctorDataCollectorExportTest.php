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
use AhmedBhs\DoctrineDoctor\Collector\Helper\DatabaseInfoCollector;
use AhmedBhs\DoctrineDoctor\Collector\Helper\DataCollectorLogger;
use AhmedBhs\DoctrineDoctor\Collector\Helper\IssueReconstructor;
use AhmedBhs\DoctrineDoctor\Collector\Helper\QueryStatsCalculator;
use AhmedBhs\DoctrineDoctor\Service\IssueDeduplicator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

final class DoctrineDoctorDataCollectorExportTest extends TestCase
{
    #[Test]
    public function it_exports_a_valid_json_document(): void
    {
        $collector = $this->createDataCollector();

        $decoded = json_decode($collector->getExportJson(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('created', $decoded);
        self::assertArrayHasKey('issues', $decoded);
        self::assertArrayHasKey('stats', $decoded);
        self::assertArrayHasKey('queries', $decoded);
    }

    #[Test]
    public function it_exports_timeline_query_metrics(): void
    {
        $collector = $this->createDataCollector();
        $this->seedCollectorData($collector, [
            'enabled' => true,
            'timeline_queries' => [
                ['sql' => 'SELECT * FROM users', 'executionMS' => 0.05],
            ],
        ]);

        $decoded = json_decode($collector->getExportJson(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(1, $decoded['queries']);
        self::assertSame('SELECT * FROM users', $decoded['queries'][0]['sql']);
        self::assertSame(1, $decoded['queries'][0]['count']);
        self::assertEqualsWithDelta(50.0, $decoded['queries'][0]['totalTimeMs'], 0.0001);
    }

    #[Test]
    public function it_escapes_closing_script_tags_so_the_payload_cannot_break_out_of_the_panel(): void
    {
        $collector = $this->createDataCollector();
        $this->seedCollectorData($collector, [
            'enabled' => true,
            'timeline_queries' => [
                ['sql' => 'SELECT * FROM t WHERE x = "</script><script>alert(1)</script>"', 'executionMS' => 0.01],
            ],
        ]);

        $json = $collector->getExportJson();

        self::assertStringNotContainsString('</script>', $json);
        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('>', $json);
        self::assertIsArray(json_decode($json, true, 512, \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_neutralises_the_comment_sequence_that_flips_the_html_parser_into_double_escaped_state(): void
    {
        $collector = $this->createDataCollector();
        $this->seedCollectorData($collector, [
            'enabled' => true,
            'timeline_queries' => [
                ['sql' => 'SELECT 1 -- <!--<script>alert(1)', 'executionMS' => 0.01],
            ],
        ]);

        $json = $collector->getExportJson();

        self::assertStringNotContainsString('<!--', $json);
        self::assertStringNotContainsString('<script', $json);

        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('SELECT 1 -- <!--<script>alert(1)', $decoded['queries'][0]['sql']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function seedCollectorData(DoctrineDoctorDataCollector $collector, array $data): void
    {
        $property = new ReflectionClass($collector)->getProperty('data');
        $property->setValue($collector, $data);
    }

    private function createDataCollector(): DoctrineDoctorDataCollector
    {
        $logger = new NullLogger();
        $helpers = new DataCollectorHelpers(
            databaseInfoCollector: new DatabaseInfoCollector(logger: $logger),
            issueReconstructor: new IssueReconstructor(),
            queryStatsCalculator: new QueryStatsCalculator(),
            dataCollectorLogger: new DataCollectorLogger(logger: $logger),
            issueDeduplicator: new IssueDeduplicator(),
        );

        return new DoctrineDoctorDataCollector(
            analyzers: [],
            doctrineDataCollector: null,
            entityManager: null,
            stopwatch: null,
            showDebugInfo: false,
            dataCollectorHelpers: $helpers,
            excludePaths: ['vendor/'],
        );
    }
}
