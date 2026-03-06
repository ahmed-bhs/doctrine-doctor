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
}
