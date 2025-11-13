<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Pattern;

use PHPUnit\Framework\TestCase;

/**
 * Comparison test: Regex vs str_contains()
 * Validates that both methods produce identical results
 */
class RegexVsStrContainsComparisonTest extends TestCase
{
    /**
     * @dataProvider sqlQueryProvider
     */
    public function testRegexAndStrContainsSameResults(string $sql, string $keyword): void
    {
        // Old method (regex)
        $regexResult = (bool) preg_match('/' . $keyword . '/i', $sql);

        // New method (str_contains)
        $strContainsResult = str_contains(strtoupper($sql), strtoupper($keyword));

        $this->assertSame(
            $regexResult,
            $strContainsResult,
            "Results differ for keyword '$keyword' in SQL: $sql"
        );
    }

    public static function sqlQueryProvider(): array
    {
        return [
            ['SELECT * FROM users ORDER BY name', 'ORDER BY'],
            ['select * from users order by name', 'ORDER BY'],
            ['SELECT * FROM users WHERE id = 1 ORDER BY created_at DESC', 'ORDER BY'],
            ['SELECT * FROM users', 'ORDER BY'],
            ['SELECT * FROM users GROUP BY status', 'ORDER BY'],
            ['SELECT COUNT(*) FROM users GROUP BY status', 'GROUP BY'],
            ['select * from orders group by user_id', 'GROUP BY'],
            ['SELECT * FROM users', 'GROUP BY'],
            ['SELECT * FROM users ORDER BY name', 'GROUP BY'],
            ['SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id', 'LEFT JOIN'],
            ['select * from users left join addresses on users.id = addresses.user_id', 'LEFT JOIN'],
            ['SELECT * FROM users', 'LEFT JOIN'],
            ['SELECT * FROM users INNER JOIN orders', 'LEFT JOIN'],
            ['SELECT DISTINCT email FROM users', 'DISTINCT'],
            ['select distinct status from orders', 'DISTINCT'],
            ['SELECT * FROM users', 'DISTINCT'],
            ['SELECT email FROM users', 'DISTINCT'],
        ];
    }
}
