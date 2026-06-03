<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\DTOHydrationAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DTOHydrationAnalyzerFalsePositiveTest extends TestCase
{
    private DTOHydrationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new DTOHydrationAnalyzer(
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_falsely_flags_simple_count_executed_twice(): void
    {
        $builder = QueryDataBuilder::create();

        $builder->addQuery('SELECT COUNT(*) FROM users WHERE active = 1', 0.5);
        $builder->addQuery('SELECT COUNT(*) FROM users WHERE active = 1', 0.5);

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        $dtoIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getDescription(), 'DTO')
                || str_contains($issue->getDescription(), 'aggregat'),
        );

        self::assertCount(0, $dtoIssues, 'Fixed: pure scalar aggregations like COUNT(*) are excluded from DTO hydration detection');
    }

    #[Test]
    public function it_falsely_flags_query_with_group_by_in_subquery(): void
    {
        $sql = 'SELECT t0_.id, t0_.name FROM users t0_ WHERE t0_.id IN ('
            . 'SELECT t1_.user_id FROM orders t1_ GROUP BY t1_.user_id HAVING COUNT(*) > 5'
            . ')';

        $builder = QueryDataBuilder::create();
        $builder->addQuery($sql, 1.0);
        $builder->addQuery($sql, 1.0);

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        $dtoIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getDescription(), 'DTO')
                || str_contains($issue->getDescription(), 'aggregat'),
        );

        self::assertCount(0, $dtoIssues, 'Fixed: GROUP BY in subqueries is stripped before aggregation detection');
    }

    #[Test]
    public function it_does_not_flag_scalar_group_by_projection(): void
    {
        $sql = 'SELECT d0_.deposit_request_id AS sclr_0, COUNT(d0_.id) AS sclr_1 '
            . 'FROM deposit_request_history d0_ WHERE d0_.event_name = ? '
            . 'GROUP BY d0_.deposit_request_id';

        $builder = QueryDataBuilder::create();
        $builder->addQuery($sql, 1.0);
        $builder->addQuery($sql, 1.0);

        $issues = $this->analyzer->analyze($builder->build());

        self::assertCount(0, $issues->toArray(), 'A GROUP BY query projecting only scalar aliases hydrates no entity and must not be flagged');
    }

    #[Test]
    public function it_flags_aggregation_projecting_entity_columns(): void
    {
        $sql = 'SELECT d0_.id AS id_0, d0_.status AS status_1, COUNT(d1_.id) AS sclr_2 '
            . 'FROM deposit_request d0_ '
            . 'LEFT JOIN deposit_request_document d1_ ON d0_.id = d1_.deposit_request_id '
            . 'GROUP BY d0_.id';

        $builder = QueryDataBuilder::create();
        $builder->addQuery($sql, 1.0);
        $builder->addQuery($sql, 1.0);

        $issues = $this->analyzer->analyze($builder->build());

        self::assertCount(1, $issues->toArray(), 'An aggregation that also projects entity columns pays the hydration cost and must be flagged');
    }
}
