<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\MissingTransactionOnBatchAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MissingTransactionOnBatchAnalyzerTest extends TestCase
{
    private MissingTransactionOnBatchAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new MissingTransactionOnBatchAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            10,
        );
    }

    #[Test]
    public function it_flags_writes_above_threshold_without_any_transaction(): void
    {
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 15; $i++) {
            $builder->addQuery("INSERT INTO author (name) VALUES ('a {$i}')", 0.05);
        }

        $issues = $this->analyzer->analyze($builder->build());

        self::assertCount(1, $issues);
        self::assertStringContainsString('Batch writes without transaction', $issues->toArray()[0]->getTitle());
    }

    #[Test]
    public function it_stays_silent_below_threshold(): void
    {
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 5; $i++) {
            $builder->addQuery("INSERT INTO author (name) VALUES ('a {$i}')", 0.05);
        }

        $issues = $this->analyzer->analyze($builder->build());

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_writes_wrapped_in_a_transaction(): void
    {
        $builder = QueryDataBuilder::create()->addQuery('START TRANSACTION');
        for ($i = 1; $i <= 15; $i++) {
            $builder->addQuery("INSERT INTO author (name) VALUES ('a {$i}')", 0.05);
        }
        $builder->addQuery('COMMIT');

        $issues = $this->analyzer->analyze($builder->build());

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_recognizes_savepoint_and_release_savepoint_as_transaction_markers(): void
    {
        $builder = QueryDataBuilder::create()->addQuery('SAVEPOINT doctrine_1');
        for ($i = 1; $i <= 15; $i++) {
            $builder->addQuery("INSERT INTO author (name) VALUES ('a {$i}')", 0.05);
        }
        $builder->addQuery('RELEASE SAVEPOINT doctrine_1');

        $issues = $this->analyzer->analyze($builder->build());

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_select_queries(): void
    {
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 50; $i++) {
            $builder->addQuery('SELECT * FROM book WHERE id = ?', 0.05);
        }

        $issues = $this->analyzer->analyze($builder->build());

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_promotes_severity_with_write_count(): void
    {
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 60; $i++) {
            $builder->addQuery("INSERT INTO author (name) VALUES ('a {$i}')", 0.05);
        }

        $issues = $this->analyzer->analyze($builder->build());

        self::assertCount(1, $issues);
        self::assertSame('critical', $issues->toArray()[0]->getSeverity()->value);
    }
}
