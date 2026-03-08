<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\CollectionJoinDetector;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\JoinOptimizationAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JoinOptimizationAnalyzerFalsePositiveTest extends TestCase
{
    private JoinOptimizationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager();
        $sqlExtractor = new SqlStructureExtractor();

        $this->analyzer = new JoinOptimizationAnalyzer(
            new CollectionJoinDetector($entityManager, $sqlExtractor),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            $sqlExtractor,
            5,
            8,
        );
    }

    #[Test]
    public function it_does_not_count_joins_inside_subqueries_as_top_level(): void
    {
        $sql = 'SELECT t0_.id FROM orders t0_ WHERE t0_.id IN ('
            . 'SELECT t1_.order_id FROM order_items t1_ '
            . 'JOIN products t2_ ON t2_.id = t1_.product_id '
            . 'JOIN categories t3_ ON t3_.id = t2_.category_id '
            . 'JOIN brands t4_ ON t4_.id = t2_.brand_id '
            . 'JOIN suppliers t5_ ON t5_.id = t4_.supplier_id '
            . 'JOIN warehouses t6_ ON t6_.id = t5_.warehouse_id '
            . 'JOIN regions t7_ ON t7_.id = t6_.region_id'
            . ')';

        $collection = QueryDataBuilder::create()->addQuery($sql, 1.0)->build();
        $issues = $this->analyzer->analyze($collection);

        $tooManyJoinsIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Too Many JOINs'),
        );

        self::assertCount(0, $tooManyJoinsIssues, 'Should not count JOINs inside subqueries as top-level JOINs');
    }

    #[Test]
    public function it_does_not_count_joins_in_exists_subquery(): void
    {
        $sql = 'SELECT u.id FROM users u '
            . 'JOIN profiles p ON p.user_id = u.id '
            . 'WHERE EXISTS ('
            . 'SELECT 1 FROM orders o '
            . 'JOIN order_items oi ON oi.order_id = o.id '
            . 'JOIN products pr ON pr.id = oi.product_id '
            . 'JOIN categories c ON c.id = pr.category_id '
            . 'JOIN brands b ON b.id = pr.brand_id '
            . 'WHERE o.user_id = u.id'
            . ')';

        $collection = QueryDataBuilder::create()->addQuery($sql, 1.0)->build();
        $issues = $this->analyzer->analyze($collection);

        $tooManyJoinsIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Too Many JOINs'),
        );

        self::assertCount(0, $tooManyJoinsIssues, 'Should not count JOINs in EXISTS subquery as top-level (only 1 real top-level JOIN)');
    }
}
