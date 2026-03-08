<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\EagerLoadingAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EagerLoadingAnalyzerFalsePositiveTest extends TestCase
{
    private EagerLoadingAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new EagerLoadingAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            7,
            10,
        );
    }

    #[Test]
    public function it_falsely_counts_joins_inside_subqueries(): void
    {
        $sql = 'SELECT t0_.id FROM articles t0_ WHERE t0_.id IN ('
            . 'SELECT t1_.article_id FROM article_tags t1_ '
            . 'JOIN tags t2_ ON t2_.id = t1_.tag_id '
            . 'JOIN tag_groups t3_ ON t3_.id = t2_.group_id '
            . 'JOIN tag_categories t4_ ON t4_.id = t3_.category_id '
            . 'JOIN tag_types t5_ ON t5_.id = t4_.type_id '
            . 'JOIN tag_families t6_ ON t6_.id = t5_.family_id '
            . 'JOIN tag_roots t7_ ON t7_.id = t6_.root_id '
            . 'JOIN tag_hierarchies t8_ ON t8_.id = t7_.hierarchy_id'
            . ')';

        $collection = QueryDataBuilder::create()->addQuery($sql, 1.0)->build();

        $issues = $this->analyzer->analyze($collection);

        $eagerIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Eager Loading')
                || str_contains($issue->getTitle(), 'JOIN'),
        );

        self::assertCount(0, $eagerIssues, 'Fixed: JOINs inside subqueries are no longer counted as top-level JOINs');
    }

    #[Test]
    public function it_falsely_reports_duplicate_issues_for_repeated_query(): void
    {
        $sql = 'SELECT t0_.id, t1_.name, t2_.addr, t3_.city, t4_.state, t5_.zip, t6_.country, t7_.phone '
            . 'FROM users t0_ '
            . 'LEFT JOIN profiles t1_ ON t1_.user_id = t0_.id '
            . 'LEFT JOIN addresses t2_ ON t2_.user_id = t0_.id '
            . 'LEFT JOIN cities t3_ ON t3_.id = t2_.city_id '
            . 'LEFT JOIN states t4_ ON t4_.id = t3_.state_id '
            . 'LEFT JOIN zip_codes t5_ ON t5_.id = t2_.zip_id '
            . 'LEFT JOIN countries t6_ ON t6_.id = t4_.country_id '
            . 'LEFT JOIN phones t7_ ON t7_.user_id = t0_.id';

        $builder = QueryDataBuilder::create();
        for ($i = 0; $i < 3; ++$i) {
            $builder->addQuery($sql, 1.0);
        }

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        $eagerIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Eager Loading')
                || str_contains($issue->getTitle(), 'JOIN'),
        );

        self::assertCount(1, $eagerIssues, 'Fixed: duplicate queries are deduplicated, producing only 1 issue');
    }
}
