<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\FindAllAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FindAllAnalyzerFalsePositiveTest extends TestCase
{
    private FindAllAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new FindAllAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_falsely_estimates_999_rows_when_row_count_is_null(): void
    {
        $collection = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t0_.name FROM small_lookup t0_', 0.5)
            ->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues), 'Known false positive: rowCount is null (no profiler row count data), so estimateRowCount defaults to 999, exceeding the 99-row threshold even for small tables');
    }

    #[Test]
    public function it_does_not_flag_query_with_inner_join(): void
    {
        $collection = QueryDataBuilder::create()
            ->addQuery(
                'SELECT t0_.id, t1_.name FROM articles t0_ INNER JOIN categories t1_ ON t1_.id = t0_.category_id',
                0.5,
            )
            ->build();

        $issues = $this->analyzer->analyze($collection);

        $findAllIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getType(), 'find_all'),
        );

        self::assertCount(0, $findAllIssues, 'INNER JOIN queries are correctly handled by the analyzer');
    }

    #[Test]
    public function it_correctly_ignores_query_with_where_clause(): void
    {
        $collection = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t0_.name FROM users t0_ WHERE t0_.active = 1', 0.5)
            ->build();

        $issues = $this->analyzer->analyze($collection);

        $findAllIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getType(), 'find_all'),
        );

        self::assertCount(0, $findAllIssues);
    }

    #[Test]
    public function it_ignores_select_distinct_count_paginator_query(): void
    {
        $sql = 'SELECT DISTINCT count(DISTINCT u0_.id) AS sclr_0 FROM `user` u0_ '
            . 'LEFT JOIN user_eco_organization u2_ ON u0_.id = u2_.user_id '
            . 'LEFT JOIN eco_organization e1_ ON e1_.id = u2_.eco_organization_id';

        $collection = QueryDataBuilder::create()
            ->addQuery($sql, 0.5)
            ->build();

        $issues = $this->analyzer->analyze($collection);

        $findAllIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getType(), 'find_all'),
        );

        self::assertCount(0, $findAllIssues);
    }

    #[Test]
    public function it_ignores_select_distinct_aggregate_functions(): void
    {
        foreach (['MAX', 'MIN', 'SUM', 'AVG'] as $aggregate) {
            $collection = QueryDataBuilder::create()
                ->addQuery(sprintf('SELECT DISTINCT %s(t0_.amount) FROM orders t0_', $aggregate), 0.5)
                ->build();

            $issues = $this->analyzer->analyze($collection);

            $findAllIssues = array_filter(
                $issues->toArray(),
                static fn ($issue): bool => str_contains($issue->getType(), 'find_all'),
            );

            self::assertCount(0, $findAllIssues, sprintf('SELECT DISTINCT %s(...) should not be flagged', $aggregate));
        }
    }
}
