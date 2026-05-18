<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\NotInSubqueryAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotInSubqueryAnalyzerTest extends TestCase
{
    private NotInSubqueryAnalyzer $analyzer;

    protected function setUp(): void
    {
        $renderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($renderer);
        $this->analyzer = new NotInSubqueryAnalyzer($suggestionFactory);
    }

    #[Test]
    public function it_ignores_queries_without_not_in(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1')
            ->addQuery('SELECT * FROM orders WHERE status IN ("open", "paid")')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_not_in_with_literal_list(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE status NOT IN ("banned", "deleted")')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_not_in_subquery(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u.* FROM users u WHERE u.id NOT IN (SELECT b.user_id FROM banned b)')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertSame('not_in_subquery', $issue->getData()['type']);
        self::assertStringContainsString('u.id', $issue->getTitle());
    }

    #[Test]
    public function it_detects_not_in_subquery_case_insensitive(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('select * from products where category_id not in ( select id from disabled_categories )')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_deduplicates_normalized_queries(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users u WHERE u.id NOT IN (SELECT b.user_id FROM banned b)')
            ->addQuery('SELECT * FROM users u WHERE u.id NOT IN (SELECT b.user_id FROM banned b)')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_ignores_non_select_statements(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('DELETE FROM users WHERE id NOT IN (SELECT user_id FROM kept)')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }
}
