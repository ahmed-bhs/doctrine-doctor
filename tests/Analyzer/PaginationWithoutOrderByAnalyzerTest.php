<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\PaginationWithoutOrderByAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaginationWithoutOrderByAnalyzerTest extends TestCase
{
    private PaginationWithoutOrderByAnalyzer $analyzer;

    protected function setUp(): void
    {
        $renderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($renderer);
        $this->analyzer = new PaginationWithoutOrderByAnalyzer($suggestionFactory);
    }

    #[Test]
    public function it_ignores_queries_without_pagination(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1')
            ->addQuery('SELECT * FROM orders WHERE status = "open"')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_pagination_with_order_by(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users ORDER BY id LIMIT 20 OFFSET 40')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_offset_without_order_by(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users LIMIT 20 OFFSET 40')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        self::assertSame('pagination_without_order_by', $issues->toArray()[0]->getData()['type']);
    }

    #[Test]
    public function it_detects_limit_without_order_by(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users LIMIT 20')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_ignores_single_row_limit(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE email = "x@y.z" LIMIT 1')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_non_select_statements(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('DELETE FROM users LIMIT 100')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }
}
