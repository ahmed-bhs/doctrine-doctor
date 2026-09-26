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
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Only a text column compared to a number loses its index: MySQL and MariaDB
 * cast every row to a number (EXPLAIN shows a full scan), and PostgreSQL
 * rejects the comparison. The reverse, `user_id = '42'`, converts the literal
 * once and keeps the index on MySQL, MariaDB and PostgreSQL alike. Column types
 * come from the Doctrine metadata, since names cannot tell them.
 */
final class ImplicitTypeConversionAnalyzerTest extends TestCase
{
    private ImplicitTypeConversionAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ImplicitTypeConversionAnalyzer(
            new SuggestionFactory(new InMemoryTemplateRenderer()),
            PlatformAnalyzerTestHelper::createTestEntityManager(),
        );
    }

    #[Test]
    public function it_detects_a_string_column_compared_to_a_numeric_literal(): void
    {
        $issues = $this->analyze('SELECT * FROM users u WHERE u.email = 123');

        self::assertCount(1, $issues);
        self::assertSame('implicit_type_conversion', $issues[0]->getData()['type']);
        self::assertStringContainsString('u.email', $issues[0]->getTitle());
        self::assertStringContainsString('String Column', $issues[0]->getTitle());
        self::assertStringContainsString('full scan', $issues[0]->getDescription());
    }

    #[Test]
    public function it_resolves_doctrine_generated_aliases(): void
    {
        $issues = $this->analyze('SELECT u0_.id AS id_0 FROM users u0_ WHERE u0_.name = 42 ORDER BY u0_.id ASC');

        self::assertCount(1, $issues);
        self::assertStringContainsString('u0_.name', $issues[0]->getTitle());
    }

    #[Test]
    public function it_detects_an_unqualified_string_column(): void
    {
        self::assertCount(1, $this->analyze('SELECT * FROM users WHERE name = 12.5'));
    }

    #[Test]
    public function it_ignores_an_integer_column_compared_to_a_quoted_number(): void
    {
        self::assertCount(0, $this->analyze("SELECT * FROM users u WHERE u.id = '42'"));
    }

    #[Test]
    public function it_ignores_an_integer_column_compared_to_a_number(): void
    {
        self::assertCount(0, $this->analyze("SELECT * FROM users u WHERE u.id = 42 AND u.name = 'Ada'"));
    }

    #[Test]
    public function it_ignores_a_string_column_compared_to_a_quoted_number(): void
    {
        self::assertCount(0, $this->analyze("SELECT * FROM users u WHERE u.email = '123'"));
    }

    #[Test]
    public function it_ignores_columns_missing_from_the_metadata(): void
    {
        self::assertCount(0, $this->analyze('SELECT * FROM legacy_codes WHERE code = 123'));
        self::assertCount(0, $this->analyze('SELECT * FROM orders WHERE created_at = 20240101'));
    }

    #[Test]
    public function it_ignores_placeholders_without_a_binding_type(): void
    {
        self::assertCount(0, $this->analyze('SELECT * FROM users u WHERE u.email = ? AND u.name = :name'));
    }

    #[Test]
    public function it_ignores_non_select_statements(): void
    {
        self::assertCount(0, $this->analyze("UPDATE users SET name = 'x' WHERE email = 5"));
    }

    #[Test]
    public function it_stays_silent_without_an_entity_manager(): void
    {
        $analyzer = new ImplicitTypeConversionAnalyzer(new SuggestionFactory(new InMemoryTemplateRenderer()));

        self::assertCount(0, $analyzer->analyze(QueryDataBuilder::create()->addQuery('SELECT * FROM users u WHERE u.email = 123')->build()));
    }

    #[Test]
    public function it_deduplicates_the_same_column(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users u WHERE u.email = 1')
            ->addQuery('SELECT * FROM users u WHERE u.email = 2')
            ->build();

        self::assertCount(1, $this->analyzer->analyze($queries));
    }

    /**
     * @return list<\AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function analyze(string $sql): array
    {
        return array_values($this->analyzer->analyze(QueryDataBuilder::create()->addQuery($sql)->build())->toArray());
    }
}
