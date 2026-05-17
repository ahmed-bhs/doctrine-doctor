<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\FunctionOnPredicateColumnAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FunctionOnPredicateColumnAnalyzerTest extends TestCase
{
    private FunctionOnPredicateColumnAnalyzer $analyzer;

    protected function setUp(): void
    {
        $renderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($renderer);
        $this->analyzer = new FunctionOnPredicateColumnAnalyzer($suggestionFactory);
    }

    #[Test]
    public function it_ignores_fast_queries(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE LOWER(email) = "x@y.z"', 0.001)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_lower_on_column(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE LOWER(email) = "x@y.z"', 50.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertSame('function_on_predicate_column', $issue->getData()['type']);
        self::assertStringContainsString('LOWER', $issue->getTitle());
        self::assertStringContainsString('email', $issue->getTitle());
    }

    #[Test]
    public function it_detects_coalesce_on_column(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE COALESCE(deleted_at, 0) = 0', 50.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        self::assertStringContainsString('COALESCE', $issues->toArray()[0]->getTitle());
    }

    #[Test]
    public function it_detects_cast_on_column(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM logs WHERE CAST(created_at AS DATE) = "2026-01-01"', 50.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        self::assertStringContainsString('CAST', $issues->toArray()[0]->getTitle());
    }

    #[Test]
    public function it_does_not_flag_date_functions_handled_by_other_analyzer(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE YEAR(created_at) = 2026', 50.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_deduplicates_same_function_column_pair(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE LOWER(email) = "a"', 50.0)
            ->addQuery('SELECT * FROM users WHERE LOWER(email) = "b"', 50.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_marks_critical_for_slow_queries(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE LOWER(email) = "x@y.z"', 250.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertSame('critical', $issues->toArray()[0]->getData()['severity']);
    }
}
