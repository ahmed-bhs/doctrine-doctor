<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\QueryCachingOpportunityAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QueryCachingOpportunityAnalyzerFalsePositiveTest extends TestCase
{
    private QueryCachingOpportunityAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new QueryCachingOpportunityAnalyzer(
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_falsely_flags_categories_table_as_static_in_ecommerce_app(): void
    {
        $collection = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t0_.name FROM categories t0_ WHERE t0_.parent_id = ?', 0.5)
            ->build();

        $issues = $this->analyzer->analyze($collection);

        $staticIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Static Table'),
        );

        self::assertGreaterThanOrEqual(1, \count($staticIssues), 'Known false positive: "categories" is hardcoded in STATIC_TABLES list, but in an e-commerce app categories change frequently (new products, seasonal categories, A/B tests)');
    }

    #[Test]
    public function it_falsely_flags_tags_table_as_static_in_cms(): void
    {
        $collection = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t0_.name FROM tags t0_', 0.3)
            ->build();

        $issues = $this->analyzer->analyze($collection);

        $staticIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Static Table'),
        );

        self::assertGreaterThanOrEqual(1, \count($staticIssues), 'Known false positive: "tags" is hardcoded in STATIC_TABLES list, but in a CMS or social platform, tags are user-generated and change constantly');
    }

    #[Test]
    public function it_falsely_flags_identical_query_with_same_params_as_frequent(): void
    {
        $builder = QueryDataBuilder::create();

        for ($i = 0; $i < 3; ++$i) {
            $builder->addQuery('SELECT t0_.id, t0_.name FROM users t0_ WHERE t0_.id = ?', 0.3);
        }

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        $frequentIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Frequent Query'),
        );

        self::assertGreaterThanOrEqual(1, \count($frequentIssues), 'Known false positive: identical queries with same params (all normalized to ?) are counted as frequent, but they may load different entities with different param values');
    }
}
