<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\OrderByWithoutLimitAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrderByWithoutLimitAnalyzerFalsePositiveTest extends TestCase
{
    private OrderByWithoutLimitAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new OrderByWithoutLimitAnalyzer(
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_falsely_flags_intentional_export_query_with_order_by(): void
    {
        $collection = QueryDataBuilder::create()
            ->addQuery(
                'SELECT t0_.id, t0_.name, t0_.email FROM users t0_ ORDER BY t0_.name ASC',
                50.0,
            )
            ->build();

        $issues = $this->analyzer->analyze($collection);

        $orderByIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'ORDER BY'),
        );

        self::assertGreaterThanOrEqual(1, \count($orderByIssues), 'Known false positive: intentional data export/CSV generation needs all rows sorted without LIMIT, but the analyzer cannot distinguish export queries from pagination-missing queries');
    }

    #[Test]
    public function it_falsely_flags_single_result_query_without_backtrace(): void
    {
        $collection = QueryDataBuilder::create()
            ->addQuery(
                'SELECT t0_.id FROM articles t0_ WHERE t0_.slug = ? ORDER BY t0_.created_at DESC',
                15.0,
            )
            ->build();

        $issues = $this->analyzer->analyze($collection);

        $orderByIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'ORDER BY'),
        );

        self::assertGreaterThanOrEqual(1, \count($orderByIssues), 'Known false positive: query is used with getOneOrNullResult but without backtrace, context detection returns "unknown" and the issue is reported');
    }

    #[Test]
    public function it_falsely_flags_single_result_query_even_with_backtrace(): void
    {
        $builder = QueryDataBuilder::create();
        $builder->addQueryWithBacktrace(
            'SELECT t0_.id FROM articles t0_ WHERE t0_.slug = ? ORDER BY t0_.created_at DESC',
            [['function' => 'getOneOrNullResult', 'class' => 'Doctrine\\ORM\\AbstractQuery']],
            2.0,
        );

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        $orderByIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'ORDER BY'),
        );

        self::assertGreaterThanOrEqual(1, \count($orderByIssues), 'Known false positive: executionMS=2.0 is treated as 2 seconds (2000ms) due to fromSeconds() conversion, exceeding the 10ms single_result threshold');
    }
}
