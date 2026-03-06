<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\FlushInLoopAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FlushInLoopAnalyzerFalsePositiveTest extends TestCase
{
    private FlushInLoopAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new FlushInLoopAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            5,
        );
    }

    #[Test]
    public function it_falsely_flags_insert_followed_by_select_last_insert_id_as_flush_boundary(): void
    {
        $builder = QueryDataBuilder::create();

        for ($i = 0; $i < 6; ++$i) {
            $builder->addQuery('INSERT INTO users (name, email) VALUES (?, ?)', 0.5);
            $builder->addQuery('SELECT LAST_INSERT_ID()', 0.1);
        }

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        $flushIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'flush'),
        );

        self::assertCount(0, $flushIssues, 'Fixed: LAST_INSERT_ID() queries are excluded from flush boundary detection');
    }

    #[Test]
    public function it_falsely_flags_insert_followed_by_select_for_sequence_as_flush(): void
    {
        $builder = QueryDataBuilder::create();

        for ($i = 0; $i < 6; ++$i) {
            $builder->addQuery('INSERT INTO orders (product_id, quantity) VALUES (?, ?)', 0.5);
            $builder->addQuery("SELECT nextval('orders_id_seq')", 0.1);
        }

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        $flushIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'flush'),
        );

        self::assertCount(0, $flushIssues, 'Fixed: nextval() sequence queries are excluded from flush boundary detection');
    }
}
