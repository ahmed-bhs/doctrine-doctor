<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Parser;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use PHPUnit\Framework\TestCase;

class SqlStructureExtractorTest extends TestCase
{
    private SqlStructureExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new SqlStructureExtractor();
    }

    public function testExtractsSimpleLeftJoin(): void
    {
        $sql = 'SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id';

        $joins = $this->extractor->extractJoins($sql);

        $this->assertCount(1, $joins);
        $this->assertSame('LEFT', $joins[0]['type']);
        $this->assertSame('orders', $joins[0]['table']);
        $this->assertSame('o', $joins[0]['alias']);
    }

    public function testExtractsMultipleJoins(): void
    {
        $sql = 'SELECT * FROM users u
                LEFT JOIN orders o ON u.id = o.user_id
                INNER JOIN products p ON o.product_id = p.id';

        $joins = $this->extractor->extractJoins($sql);

        $this->assertCount(2, $joins);

        // First JOIN
        $this->assertSame('LEFT', $joins[0]['type']);
        $this->assertSame('orders', $joins[0]['table']);
        $this->assertSame('o', $joins[0]['alias']);

        // Second JOIN
        $this->assertSame('INNER', $joins[1]['type']);
        $this->assertSame('products', $joins[1]['table']);
        $this->assertSame('p', $joins[1]['alias']);
    }

    public function testNormalizesLeftOuterJoin(): void
    {
        $sql = 'SELECT * FROM users u LEFT OUTER JOIN orders o ON u.id = o.user_id';

        $joins = $this->extractor->extractJoins($sql);

        $this->assertCount(1, $joins);
        $this->assertSame('LEFT', $joins[0]['type']); // Normalized
    }

    public function testJoinWithoutAlias(): void
    {
        $sql = 'SELECT * FROM users u JOIN orders ON u.id = orders.user_id';

        $joins = $this->extractor->extractJoins($sql);

        $this->assertCount(1, $joins);
        $this->assertSame('INNER', $joins[0]['type']);
        $this->assertSame('orders', $joins[0]['table']);
        $this->assertNull($joins[0]['alias']);
    }

    public function testJoinWithAsKeyword(): void
    {
        $sql = 'SELECT * FROM users u LEFT JOIN orders AS o ON u.id = o.user_id';

        $joins = $this->extractor->extractJoins($sql);

        $this->assertCount(1, $joins);
        $this->assertSame('o', $joins[0]['alias']);
    }

    public function testDoesNotCaptureOnAsAlias(): void
    {
        // This was a bug with regex: capturing 'ON' as alias
        $sql = 'SELECT * FROM users u LEFT JOIN orders ON u.id = orders.user_id';

        $joins = $this->extractor->extractJoins($sql);

        $this->assertCount(1, $joins);
        $this->assertNull($joins[0]['alias']); // NOT 'ON'
    }

    public function testExtractsMainTable(): void
    {
        $sql = 'SELECT * FROM users u WHERE u.id = 1';

        $mainTable = $this->extractor->extractMainTable($sql);

        $this->assertNotNull($mainTable);
        $this->assertSame('users', $mainTable['table']);
        $this->assertSame('u', $mainTable['alias']);
    }

    public function testExtractsMainTableWithoutAlias(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 1';

        $mainTable = $this->extractor->extractMainTable($sql);

        $this->assertNotNull($mainTable);
        $this->assertSame('users', $mainTable['table']);
        $this->assertNull($mainTable['alias']);
    }

    public function testExtractsAllTables(): void
    {
        $sql = 'SELECT * FROM users u
                LEFT JOIN orders o ON u.id = o.user_id
                INNER JOIN products p ON o.product_id = p.id';

        $tables = $this->extractor->extractAllTables($sql);

        $this->assertCount(3, $tables);

        // FROM table
        $this->assertSame('users', $tables[0]['table']);
        $this->assertSame('u', $tables[0]['alias']);
        $this->assertSame('from', $tables[0]['source']);

        // First JOIN
        $this->assertSame('orders', $tables[1]['table']);
        $this->assertSame('o', $tables[1]['alias']);
        $this->assertSame('join', $tables[1]['source']);

        // Second JOIN
        $this->assertSame('products', $tables[2]['table']);
        $this->assertSame('p', $tables[2]['alias']);
        $this->assertSame('join', $tables[2]['source']);
    }

    public function testHasJoinReturnsTrueWhenJoinPresent(): void
    {
        $sql = 'SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id';

        $this->assertTrue($this->extractor->hasJoin($sql));
    }

    public function testHasJoinReturnsFalseWhenNoJoin(): void
    {
        $sql = 'SELECT * FROM users u WHERE u.id = 1';

        $this->assertFalse($this->extractor->hasJoin($sql));
    }

    public function testCountJoins(): void
    {
        $sql = 'SELECT * FROM users u
                LEFT JOIN orders o ON u.id = o.user_id
                INNER JOIN products p ON o.product_id = p.id
                LEFT JOIN categories c ON p.category_id = c.id';

        $this->assertSame(3, $this->extractor->countJoins($sql));
    }

    public function testHandlesComplexRealWorldQuery(): void
    {
        // Real Sylius query
        $sql = "SELECT t0.id AS id_1, t0.code AS code_2, t0.enabled AS enabled_3
                FROM sylius_channel t0_
                LEFT JOIN sylius_channel_locales t1_ ON t0_.id = t1_.channel_id
                INNER JOIN sylius_locale t2_ ON t2_.id = t1_.locale_id
                WHERE t2_.code = ? AND t0_.enabled = ?";

        $joins = $this->extractor->extractJoins($sql);

        $this->assertCount(2, $joins);

        // First JOIN
        $this->assertSame('LEFT', $joins[0]['type']);
        $this->assertSame('sylius_channel_locales', $joins[0]['table']);
        $this->assertSame('t1_', $joins[0]['alias']);

        // Second JOIN
        $this->assertSame('INNER', $joins[1]['type']);
        $this->assertSame('sylius_locale', $joins[1]['table']);
        $this->assertSame('t2_', $joins[1]['alias']);
    }

    public function testReturnsEmptyArrayForNonSelectQuery(): void
    {
        $sql = 'UPDATE users SET name = ? WHERE id = ?';

        $joins = $this->extractor->extractJoins($sql);

        $this->assertSame([], $joins);
    }

    public function testReturnsEmptyArrayForInvalidSql(): void
    {
        $sql = 'NOT A VALID SQL QUERY';

        $joins = $this->extractor->extractJoins($sql);

        $this->assertSame([], $joins);
    }
}
