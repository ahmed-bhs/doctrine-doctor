<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\ImplicitTypeConversionAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImplicitTypeConversionAnalyzerTest extends TestCase
{
    private ImplicitTypeConversionAnalyzer $analyzer;

    protected function setUp(): void
    {
        $renderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($renderer);
        $this->analyzer = new ImplicitTypeConversionAnalyzer($suggestionFactory);
    }

    #[Test]
    public function it_detects_numeric_column_compared_to_string_literal(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE user_id = \'42\'')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertSame('implicit_type_conversion', $issue->getData()['type']);
        self::assertStringContainsString('user_id', $issue->getTitle());
        self::assertStringContainsString('String', $issue->getTitle());
    }

    #[Test]
    public function it_detects_id_alias_form(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users u WHERE u.id = \'7\'')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_ignores_numeric_column_with_unquoted_literal(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE user_id = 42')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_non_numeric_column_with_string_literal(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE email = \'a@b.c\'')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_numeric_column_compared_to_non_numeric_string(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE user_id = \'abc\'')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_date_column_compared_to_integer_literal(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE created_at = 1700000000')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        self::assertStringContainsString('Date Column', $issues->toArray()[0]->getTitle());
    }

    #[Test]
    public function it_ignores_placeholder_parameters(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE user_id = ?')
            ->addQuery('SELECT * FROM orders WHERE user_id = :id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_deduplicates_same_column_kind_pair(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM orders WHERE user_id = \'1\'')
            ->addQuery('SELECT * FROM orders WHERE user_id = \'2\'')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_ignores_non_select_statements(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('UPDATE orders SET status = "x" WHERE user_id = \'42\'')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }
}
