<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Infrastructure\Cache;

use AhmedBhs\DoctrineDoctor\Infrastructure\Cache\SqlParserCache;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SqlParserCacheTest extends TestCase
{
    protected function setUp(): void
    {
        SqlParserCache::clear();
    }

    protected function tearDown(): void
    {
        SqlParserCache::clear();
    }

    #[Test]
    public function it_parses_sql(): void
    {
        $parser = SqlParserCache::parse('SELECT u.id FROM users u WHERE u.id = 1');

        self::assertInstanceOf(SelectStatement::class, $parser->statements[0]);
    }

    #[Test]
    public function it_returns_the_same_parser_for_the_same_sql(): void
    {
        $sql = 'SELECT u.id FROM users u WHERE u.id = 1';

        self::assertSame(SqlParserCache::parse($sql), SqlParserCache::parse($sql));
    }

    #[Test]
    public function it_returns_distinct_parsers_for_distinct_sql(): void
    {
        self::assertNotSame(
            SqlParserCache::parse('SELECT id FROM users'),
            SqlParserCache::parse('SELECT id FROM orders'),
        );
    }

    #[Test]
    public function it_evicts_the_oldest_entry_when_full(): void
    {
        $first = SqlParserCache::parse('SELECT 0');

        for ($i = 1; $i <= 512; ++$i) {
            SqlParserCache::parse('SELECT ' . $i);
        }

        self::assertNotSame($first, SqlParserCache::parse('SELECT 0'));
    }

    #[Test]
    public function clear_drops_cached_parsers(): void
    {
        $sql = 'SELECT id FROM users';
        $parser = SqlParserCache::parse($sql);

        SqlParserCache::clear();

        self::assertNotSame($parser, SqlParserCache::parse($sql));
    }
}
