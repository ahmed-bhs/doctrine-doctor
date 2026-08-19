<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Parser;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlPerformanceAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * hasOffset() answers whether a query actually skips rows.
 *
 * The SQL parser reports an offset of 0 for a bare LIMIT, so a naive
 * "is the offset set" check reports an offset for every LIMIT query.
 * Callers rely on this to tell a first page from a later one.
 */
final class SqlPerformanceAnalyzerOffsetTest extends TestCase
{
    private SqlPerformanceAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new SqlPerformanceAnalyzer();
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function offsetProvider(): array
    {
        return [
            'bare limit has no offset'              => ['SELECT a FROM t LIMIT 10', false],
            'explicit zero offset is not an offset' => ['SELECT a FROM t LIMIT 10 OFFSET 0', false],
            'non zero offset'                       => ['SELECT a FROM t LIMIT 10 OFFSET 20', true],
            'mysql comma syntax with offset'        => ['SELECT a FROM t LIMIT 20, 10', true],
            'mysql comma syntax first page'         => ['SELECT a FROM t LIMIT 0, 10', false],
            'standalone offset'                     => ['SELECT a FROM t OFFSET 5', true],
            'standalone zero offset'                => ['SELECT a FROM t OFFSET 0', false],
            'no pagination at all'                  => ['SELECT a FROM t', false],
            'non select statement'                  => ['UPDATE t SET a = 1', false],
        ];
    }

    #[Test]
    #[DataProvider('offsetProvider')]
    public function it_detects_only_offsets_that_skip_rows(string $sql, bool $expected): void
    {
        self::assertSame($expected, $this->analyzer->hasOffset($sql));
    }
}
