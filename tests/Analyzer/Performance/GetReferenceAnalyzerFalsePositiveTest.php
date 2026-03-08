<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\GetReferenceAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GetReferenceAnalyzerFalsePositiveTest extends TestCase
{
    private GetReferenceAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new GetReferenceAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            2,
        );
    }

    #[Test]
    public function it_falsely_flags_fk_lookup_as_find_by_id_candidate(): void
    {
        $builder = QueryDataBuilder::create();

        $builder->addQuery(
            'SELECT t0_.id AS id_0, t0_.name AS name_1 FROM customers t0_ WHERE t0_.customer_id = ?',
            0.5,
        );
        $builder->addQuery(
            'SELECT t0_.id AS id_0, t0_.name AS name_1 FROM suppliers t0_ WHERE t0_.supplier_id = ?',
            0.5,
        );

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues), 'Known false positive: Pattern 5/6 matches WHERE customer_id = ? and supplier_id = ? as simple find() candidates, but these are FK lookups that load related entities by foreign key, not by primary key. getReference() would not work here');
    }

    #[Test]
    public function it_falsely_flags_lazy_loaded_queries_without_backtrace_as_explicit_find(): void
    {
        $builder = QueryDataBuilder::create();

        $builder->addQuery('SELECT t0_.id AS id_0, t0_.name AS name_1 FROM users t0_ WHERE t0_.id = ?', 0.3);
        $builder->addQuery('SELECT t0_.id AS id_0, t0_.name AS name_1 FROM users t0_ WHERE t0_.id = ?', 0.3);

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues));

        $issue = $issues->toArray()[0];
        self::assertStringContainsString('find()', $issue->getTitle(), 'Known false positive: without backtrace, lazy-loaded queries default to "explicit_find" context and suggest getReference(), which is incorrect for lazy loading');
    }
}
