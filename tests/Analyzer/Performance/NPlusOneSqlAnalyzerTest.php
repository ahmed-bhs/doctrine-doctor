<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\NPlusOneSqlAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NPlusOneSqlAnalyzerTest extends TestCase
{
    private NPlusOneSqlAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new NPlusOneSqlAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            3,
        );
    }

    #[Test]
    public function it_detects_repeated_select_pattern_above_threshold(): void
    {
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 5; $i++) {
            $builder->addQuery('SELECT id, title FROM book WHERE author_id = ?', 0.1);
        }

        $issues = $this->analyzer->analyze($builder->build());

        self::assertCount(1, $issues);
        self::assertStringContainsString('N+1 SQL pattern (DBAL)', $issues->toArray()[0]->getTitle());
    }

    #[Test]
    public function it_stays_silent_below_threshold(): void
    {
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 2; $i++) {
            $builder->addQuery('SELECT id, title FROM book WHERE author_id = ?', 0.1);
        }

        $issues = $this->analyzer->analyze($builder->build());

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_groups_only_queries_with_same_pattern(): void
    {
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 5; $i++) {
            $builder->addQuery('SELECT id FROM book WHERE author_id = ?', 0.1);
        }
        $builder->addQuery('SELECT id FROM author WHERE name = ?', 0.1);

        $issues = $this->analyzer->analyze($builder->build());

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_promotes_severity_with_repetition_count(): void
    {
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 25; $i++) {
            $builder->addQuery('SELECT id FROM book WHERE author_id = ?', 0.1);
        }

        $issues = $this->analyzer->analyze($builder->build());

        self::assertCount(1, $issues);
        self::assertSame('critical', $issues->toArray()[0]->getSeverity()->value);
    }

    #[Test]
    public function it_ignores_writes(): void
    {
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 5; $i++) {
            $builder->addQuery("INSERT INTO book (title) VALUES ('book {$i}')", 0.1);
        }

        $issues = $this->analyzer->analyze($builder->build());

        self::assertCount(0, $issues);
    }
}
