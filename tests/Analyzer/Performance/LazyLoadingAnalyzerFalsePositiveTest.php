<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\LazyLoadingAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LazyLoadingAnalyzerFalsePositiveTest extends TestCase
{
    private LazyLoadingAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new LazyLoadingAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            10,
        );
    }

    #[Test]
    public function it_falsely_flags_sequential_queries_from_graphql_parallel_resolvers(): void
    {
        $builder = QueryDataBuilder::create();

        for ($i = 0; $i < 12; ++$i) {
            $builder->addQuery(
                'SELECT t0_.id AS id_0, t0_.name AS name_1 FROM users t0_ WHERE t0_.id = ?',
                0.3,
            );
        }

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues), 'Known false positive: GraphQL resolves multiple user fields in parallel, generating sequential SELECT by ID queries that look like lazy loading in a loop');
    }

    #[Test]
    public function it_falsely_flags_queries_with_avg_gap_under_5_as_loop(): void
    {
        $builder = QueryDataBuilder::create();

        $builder->addQuery('SELECT id FROM articles WHERE id = ?', 0.1);
        $builder->addQuery('SELECT t0_.id FROM users t0_ WHERE t0_.id = ?', 0.1);
        $builder->addQuery('SELECT id FROM articles WHERE id = ?', 0.1);
        $builder->addQuery('SELECT t0_.id FROM users t0_ WHERE t0_.id = ?', 0.1);
        $builder->addQuery('SELECT id FROM articles WHERE id = ?', 0.1);
        $builder->addQuery('SELECT t0_.id FROM users t0_ WHERE t0_.id = ?', 0.1);
        $builder->addQuery('SELECT id FROM articles WHERE id = ?', 0.1);
        $builder->addQuery('SELECT t0_.id FROM users t0_ WHERE t0_.id = ?', 0.1);
        $builder->addQuery('SELECT id FROM articles WHERE id = ?', 0.1);
        $builder->addQuery('SELECT t0_.id FROM users t0_ WHERE t0_.id = ?', 0.1);
        $builder->addQuery('SELECT id FROM articles WHERE id = ?', 0.1);
        $builder->addQuery('SELECT t0_.id FROM users t0_ WHERE t0_.id = ?', 0.1);
        $builder->addQuery('SELECT id FROM articles WHERE id = ?', 0.1);
        $builder->addQuery('SELECT t0_.id FROM users t0_ WHERE t0_.id = ?', 0.1);
        $builder->addQuery('SELECT id FROM articles WHERE id = ?', 0.1);
        $builder->addQuery('SELECT t0_.id FROM users t0_ WHERE t0_.id = ?', 0.1);
        $builder->addQuery('SELECT id FROM articles WHERE id = ?', 0.1);
        $builder->addQuery('SELECT t0_.id FROM users t0_ WHERE t0_.id = ?', 0.1);
        $builder->addQuery('SELECT id FROM articles WHERE id = ?', 0.1);
        $builder->addQuery('SELECT t0_.id FROM users t0_ WHERE t0_.id = ?', 0.1);

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        $lazyLoadIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Lazy Loading'),
        );

        self::assertCount(0, $lazyLoadIssues, 'Fixed: avgGap threshold reduced from 5 to 1.5, interleaved queries with gap=2 are no longer flagged');
    }
}
