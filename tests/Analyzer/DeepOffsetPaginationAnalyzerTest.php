<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\DeepOffsetPaginationAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeepOffsetPaginationAnalyzerTest extends TestCase
{
    private DeepOffsetPaginationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $renderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($renderer);
        $this->analyzer = new DeepOffsetPaginationAnalyzer($suggestionFactory);
    }

    #[Test]
    public function it_ignores_queries_without_offset(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1')
            ->addQuery('SELECT * FROM orders ORDER BY id LIMIT 20')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_shallow_offset(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users ORDER BY id LIMIT 20 OFFSET 100')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_deep_offset_keyword_form(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users ORDER BY id LIMIT 20 OFFSET 5000')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertSame('deep_offset_pagination', $issue->getData()['type']);
        self::assertStringContainsString('OFFSET 5000', $issue->getTitle());
    }

    #[Test]
    public function it_detects_deep_offset_mysql_limit_comma_form(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users ORDER BY id LIMIT 50000, 20')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        self::assertStringContainsString('OFFSET 50000', $issues->toArray()[0]->getTitle());
    }

    #[Test]
    public function it_marks_critical_above_critical_threshold(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users ORDER BY id LIMIT 20 OFFSET 50000')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertSame('critical', $issues->toArray()[0]->getData()['severity']);
    }

    #[Test]
    public function it_deduplicates_identical_normalized_queries(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users ORDER BY id LIMIT 20 OFFSET 5000')
            ->addQuery('SELECT * FROM users ORDER BY id LIMIT 20 OFFSET 5000')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_ignores_non_select_statements(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('UPDATE users SET status = "active" LIMIT 20 OFFSET 5000')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }
}
