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

    // ========================================================================
    // normalizeQuery() Tests - Critical method used by 7 analyzers
    // ========================================================================

    public function testNormalizeQueryReplacesStringLiterals(): void
    {
        // Given: Query with string literals
        $sql = "SELECT * FROM users WHERE name = 'John' AND email = 'john@example.com'";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: String literals should be replaced with ? (output is UPPERCASE)
        $this->assertStringContainsString('NAME = ?', $normalized);
        $this->assertStringContainsString('EMAIL = ?', $normalized);
        $this->assertStringNotContainsString('John', $normalized);
        $this->assertStringNotContainsString('john@example.com', $normalized);
    }

    public function testNormalizeQueryReplacesNumericLiterals(): void
    {
        // Given: Query with numeric literals
        $sql = 'SELECT * FROM users WHERE id = 123 AND age > 25 AND score = 98.5';

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: Numeric literals should be replaced with ? (output is UPPERCASE)
        $this->assertStringContainsString('ID = ?', $normalized);
        $this->assertStringContainsString('AGE > ?', $normalized);
        $this->assertStringContainsString('SCORE = ?', $normalized);
        $this->assertStringNotContainsString('123', $normalized);
        $this->assertStringNotContainsString('25', $normalized);
        $this->assertStringNotContainsString('98.5', $normalized);
    }

    public function testNormalizeQueryHandlesInClause(): void
    {
        // Given: Query with IN clause
        $sql = "SELECT * FROM users WHERE id IN (1, 2, 3, 4, 5)";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: IN clause should be normalized to IN (?)
        $this->assertStringContainsString('IN (?)', $normalized);
        $this->assertStringNotContainsString('1, 2, 3, 4, 5', $normalized);
    }

    public function testNormalizeQueryNormalizesWhitespace(): void
    {
        // Given: Query with irregular whitespace
        $sql = "SELECT  *  FROM   users    WHERE  id   =   ?";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: Whitespace should be normalized (single spaces, UPPERCASE)
        $this->assertStringNotContainsString('  ', $normalized); // No double spaces
        $this->assertSame('SELECT * FROM USERS WHERE ID = ?', $normalized);
    }

    public function testNormalizeQueryHandlesUpdateStatements(): void
    {
        // Given: UPDATE query with literals
        $sql = "UPDATE users SET name = 'John', age = 30 WHERE id = 5";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: All values should be normalized (output is UPPERCASE)
        $this->assertStringContainsString('UPDATE USERS SET', $normalized);
        $this->assertStringContainsString('NAME = ?', $normalized);
        $this->assertStringContainsString('AGE = ?', $normalized);
        $this->assertStringContainsString('WHERE ID = ?', $normalized);
        $this->assertStringNotContainsString('John', $normalized);
        $this->assertStringNotContainsString('30', $normalized);
    }

    public function testNormalizeQueryHandlesDeleteStatements(): void
    {
        // Given: DELETE query with literals
        $sql = "DELETE FROM users WHERE age > 100 AND status = 'inactive'";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: All values should be normalized (output is UPPERCASE)
        $this->assertStringContainsString('DELETE FROM USERS WHERE', $normalized);
        $this->assertStringContainsString('AGE > ?', $normalized);
        $this->assertStringContainsString('STATUS = ?', $normalized);
        $this->assertStringNotContainsString('100', $normalized);
        $this->assertStringNotContainsString('inactive', $normalized);
    }

    public function testNormalizeQueryHandlesComplexSelectWithJoins(): void
    {
        // Given: Complex query with JOINs and multiple conditions
        $sql = "SELECT u.*, o.total FROM users u
                LEFT JOIN orders o ON u.id = o.user_id
                WHERE u.created_at > '2024-01-01' AND o.status = 'completed'
                AND o.total > 100";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: Structure preserved, values normalized (output is UPPERCASE)
        $this->assertStringContainsString('LEFT JOIN ORDERS', $normalized);
        $this->assertStringContainsString('U.ID = ?', $normalized); // JOIN conditions also normalized
        $this->assertStringContainsString('CREATED_AT > ?', $normalized);
        $this->assertStringContainsString('STATUS = ?', $normalized);
        $this->assertStringContainsString('TOTAL > ?', $normalized);
        $this->assertStringNotContainsString('2024-01-01', $normalized);
        $this->assertStringNotContainsString('completed', $normalized);
        $this->assertStringNotContainsString('100', $normalized);
    }

    public function testNormalizeQueryPreservesParameterizedQueries(): void
    {
        // Given: Already parameterized query
        $sql = 'SELECT * FROM users WHERE id = ? AND name = ?';

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: Placeholders should be preserved (output is UPPERCASE)
        $this->assertStringContainsString('ID = ?', $normalized);
        $this->assertStringContainsString('NAME = ?', $normalized);
    }

    public function testNormalizeQueryHandlesMultipleInClauses(): void
    {
        // Given: Query with multiple IN clauses
        $sql = "SELECT * FROM orders WHERE status IN ('pending', 'processing')
                AND user_id IN (1, 2, 3)";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: Both IN clauses normalized
        // Count occurrences of "IN (?)"
        $count = substr_count($normalized, 'IN (?)');
        $this->assertGreaterThanOrEqual(2, $count);
        $this->assertStringNotContainsString('pending', $normalized);
        $this->assertStringNotContainsString('1, 2, 3', $normalized);
    }

    public function testNormalizeQueryHandlesStringWithEscapedQuotes(): void
    {
        // Given: Query with escaped quotes in string
        $sql = "SELECT * FROM users WHERE bio = 'It\\'s a beautiful day'";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: String should be replaced with ? (output is UPPERCASE)
        $this->assertStringContainsString('BIO = ?', $normalized);
        $this->assertStringNotContainsString("It\\'s", $normalized);
    }

    public function testNormalizeQueryHandlesCaseInsensitivity(): void
    {
        // Given: Query with mixed case keywords
        $sql = "select * from users where id = 123";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: Should normalize regardless of case (output is UPPERCASE)
        $this->assertStringContainsString('SELECT', $normalized);
        $this->assertStringContainsString('FROM', $normalized);
        $this->assertStringContainsString('WHERE', $normalized);
        $this->assertStringContainsString('ID = ?', $normalized);
    }

    public function testNormalizeQueryGroupsIdenticalPatterns(): void
    {
        // Given: Two queries that should normalize to same pattern
        $sql1 = "SELECT * FROM users WHERE id = 123";
        $sql2 = "SELECT * FROM users WHERE id = 456";

        // When: We normalize both queries
        $normalized1 = $this->extractor->normalizeQuery($sql1);
        $normalized2 = $this->extractor->normalizeQuery($sql2);

        // Then: Should produce identical normalized patterns
        $this->assertSame($normalized1, $normalized2);
    }

    public function testNormalizeQueryFallsBackToRegexForInvalidSql(): void
    {
        // Given: Invalid SQL that parser can't handle
        $sql = "SOME WEIRD QUERY THAT LOOKS LIKE SQL WITH 123 AND 'string'";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: Should still normalize using regex fallback
        // At minimum, whitespace should be normalized
        $this->assertIsString($normalized);
        $this->assertNotEmpty($normalized);
    }

    public function testNormalizeQueryHandlesSubqueries(): void
    {
        // Given: Query with subquery
        $sql = "SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE total > 100)";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: Subquery is normalized to IN (?) - subqueries are collapsed
        $this->assertStringContainsString('SELECT', $normalized);
        $this->assertStringContainsString('IN (?)', $normalized);
        $this->assertStringNotContainsString('100', $normalized);
    }

    // ========================================================================
    // extractAggregationFunctions() Tests
    // ========================================================================

    public function testExtractsCountFunction(): void
    {
        $sql = 'SELECT COUNT(id) FROM orders';

        $aggregations = $this->extractor->extractAggregationFunctions($sql);

        $this->assertContains('COUNT', $aggregations);
        $this->assertCount(1, $aggregations);
    }

    public function testExtractsMultipleAggregationFunctions(): void
    {
        $sql = 'SELECT COUNT(id), SUM(total), AVG(price) FROM orders';

        $aggregations = $this->extractor->extractAggregationFunctions($sql);

        $this->assertContains('COUNT', $aggregations);
        $this->assertContains('SUM', $aggregations);
        $this->assertContains('AVG', $aggregations);
        $this->assertCount(3, $aggregations);
    }

    public function testExtractsMinMaxFunctions(): void
    {
        $sql = 'SELECT MIN(price), MAX(price) FROM products';

        $aggregations = $this->extractor->extractAggregationFunctions($sql);

        $this->assertContains('MIN', $aggregations);
        $this->assertContains('MAX', $aggregations);
        $this->assertCount(2, $aggregations);
    }

    public function testReturnsEmptyArrayWhenNoAggregations(): void
    {
        $sql = 'SELECT id, name FROM users';

        $aggregations = $this->extractor->extractAggregationFunctions($sql);

        $this->assertSame([], $aggregations);
    }

    public function testAggregationReturnsEmptyArrayForNonSelectQuery(): void
    {
        $sql = 'UPDATE orders SET status = ? WHERE id = ?';

        $aggregations = $this->extractor->extractAggregationFunctions($sql);

        $this->assertSame([], $aggregations);
    }

    // ========================================================================
    // findIsNotNullFieldOnAlias() Tests
    // ========================================================================

    public function testFindsIsNotNullFieldOnAlias(): void
    {
        $sql = 'SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id WHERE o.status IS NOT NULL';

        $fieldName = $this->extractor->findIsNotNullFieldOnAlias($sql, 'o');

        $this->assertSame('status', $fieldName);
    }

    public function testReturnsNullWhenNoIsNotNullCondition(): void
    {
        $sql = 'SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id WHERE o.status = ?';

        $fieldName = $this->extractor->findIsNotNullFieldOnAlias($sql, 'o');

        $this->assertNull($fieldName);
    }

    public function testReturnsNullWhenAliasNotFound(): void
    {
        $sql = 'SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id WHERE o.status IS NOT NULL';

        $fieldName = $this->extractor->findIsNotNullFieldOnAlias($sql, 'x');

        $this->assertNull($fieldName);
    }

    public function testFindsIsNotNullWithoutJoin(): void
    {
        $sql = 'SELECT * FROM users u WHERE u.email IS NOT NULL';

        $fieldName = $this->extractor->findIsNotNullFieldOnAlias($sql, 'u');

        $this->assertSame('email', $fieldName);
    }

    public function testFindsFirstIsNotNullFieldWhenMultiple(): void
    {
        $sql = 'SELECT * FROM users u WHERE u.email IS NOT NULL AND u.name IS NOT NULL';

        $fieldName = $this->extractor->findIsNotNullFieldOnAlias($sql, 'u');

        // Should return first match
        $this->assertSame('email', $fieldName);
    }

    public function testHandlesCaseInsensitiveIsNotNull(): void
    {
        $sql = 'SELECT * FROM users u WHERE u.email is not null';

        $fieldName = $this->extractor->findIsNotNullFieldOnAlias($sql, 'u');

        $this->assertSame('email', $fieldName);
    }
}
