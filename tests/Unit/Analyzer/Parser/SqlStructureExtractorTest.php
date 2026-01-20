<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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

    public function test_extracts_simple_left_join(): void
    {
        $sql = 'SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id';

        $joins = $this->extractor->extractJoins($sql);

        self::assertCount(1, $joins);
        self::assertSame('LEFT', $joins[0]['type']);
        self::assertSame('orders', $joins[0]['table']);
        self::assertSame('o', $joins[0]['alias']);
    }

    public function test_extracts_multiple_joins(): void
    {
        $sql = 'SELECT * FROM users u
                LEFT JOIN orders o ON u.id = o.user_id
                INNER JOIN products p ON o.product_id = p.id';

        $joins = $this->extractor->extractJoins($sql);

        self::assertCount(2, $joins);

        // First JOIN
        self::assertSame('LEFT', $joins[0]['type']);
        self::assertSame('orders', $joins[0]['table']);
        self::assertSame('o', $joins[0]['alias']);

        // Second JOIN
        self::assertSame('INNER', $joins[1]['type']);
        self::assertSame('products', $joins[1]['table']);
        self::assertSame('p', $joins[1]['alias']);
    }

    public function test_normalizes_left_outer_join(): void
    {
        $sql = 'SELECT * FROM users u LEFT OUTER JOIN orders o ON u.id = o.user_id';

        $joins = $this->extractor->extractJoins($sql);

        self::assertCount(1, $joins);
        self::assertSame('LEFT', $joins[0]['type']); // Normalized
    }

    public function test_join_without_alias(): void
    {
        $sql = 'SELECT * FROM users u JOIN orders ON u.id = orders.user_id';

        $joins = $this->extractor->extractJoins($sql);

        self::assertCount(1, $joins);
        self::assertSame('INNER', $joins[0]['type']);
        self::assertSame('orders', $joins[0]['table']);
        self::assertNull($joins[0]['alias']);
    }

    public function test_join_with_as_keyword(): void
    {
        $sql = 'SELECT * FROM users u LEFT JOIN orders AS o ON u.id = o.user_id';

        $joins = $this->extractor->extractJoins($sql);

        self::assertCount(1, $joins);
        self::assertSame('o', $joins[0]['alias']);
    }

    public function test_does_not_capture_on_as_alias(): void
    {
        // This was a bug with regex: capturing 'ON' as alias
        $sql = 'SELECT * FROM users u LEFT JOIN orders ON u.id = orders.user_id';

        $joins = $this->extractor->extractJoins($sql);

        self::assertCount(1, $joins);
        self::assertNull($joins[0]['alias']); // NOT 'ON'
    }

    public function test_extracts_main_table(): void
    {
        $sql = 'SELECT * FROM users u WHERE u.id = 1';

        $mainTable = $this->extractor->extractMainTable($sql);

        self::assertNotNull($mainTable);
        self::assertSame('users', $mainTable['table']);
        self::assertSame('u', $mainTable['alias']);
    }

    public function test_extracts_main_table_without_alias(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 1';

        $mainTable = $this->extractor->extractMainTable($sql);

        self::assertNotNull($mainTable);
        self::assertSame('users', $mainTable['table']);
        self::assertNull($mainTable['alias']);
    }

    public function test_extracts_all_tables(): void
    {
        $sql = 'SELECT * FROM users u
                LEFT JOIN orders o ON u.id = o.user_id
                INNER JOIN products p ON o.product_id = p.id';

        $tables = $this->extractor->extractAllTables($sql);

        self::assertCount(3, $tables);

        // FROM table
        self::assertSame('users', $tables[0]['table']);
        self::assertSame('u', $tables[0]['alias']);
        self::assertSame('from', $tables[0]['source']);

        // First JOIN
        self::assertSame('orders', $tables[1]['table']);
        self::assertSame('o', $tables[1]['alias']);
        self::assertSame('join', $tables[1]['source']);

        // Second JOIN
        self::assertSame('products', $tables[2]['table']);
        self::assertSame('p', $tables[2]['alias']);
        self::assertSame('join', $tables[2]['source']);
    }

    public function test_has_join_returns_true_when_join_present(): void
    {
        $sql = 'SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id';

        self::assertTrue($this->extractor->hasJoin($sql));
    }

    public function test_has_join_returns_false_when_no_join(): void
    {
        $sql = 'SELECT * FROM users u WHERE u.id = 1';

        self::assertFalse($this->extractor->hasJoin($sql));
    }

    public function test_count_joins(): void
    {
        $sql = 'SELECT * FROM users u
                LEFT JOIN orders o ON u.id = o.user_id
                INNER JOIN products p ON o.product_id = p.id
                LEFT JOIN categories c ON p.category_id = c.id';

        self::assertSame(3, $this->extractor->countJoins($sql));
    }

    public function test_handles_complex_real_world_query(): void
    {
        // Real Sylius query
        $sql = "SELECT t0.id AS id_1, t0.code AS code_2, t0.enabled AS enabled_3
                FROM sylius_channel t0_
                LEFT JOIN sylius_channel_locales t1_ ON t0_.id = t1_.channel_id
                INNER JOIN sylius_locale t2_ ON t2_.id = t1_.locale_id
                WHERE t2_.code = ? AND t0_.enabled = ?";

        $joins = $this->extractor->extractJoins($sql);

        self::assertCount(2, $joins);

        // First JOIN
        self::assertSame('LEFT', $joins[0]['type']);
        self::assertSame('sylius_channel_locales', $joins[0]['table']);
        self::assertSame('t1_', $joins[0]['alias']);

        // Second JOIN
        self::assertSame('INNER', $joins[1]['type']);
        self::assertSame('sylius_locale', $joins[1]['table']);
        self::assertSame('t2_', $joins[1]['alias']);
    }

    public function test_returns_empty_array_for_non_select_query(): void
    {
        $sql = 'UPDATE users SET name = ? WHERE id = ?';

        $joins = $this->extractor->extractJoins($sql);

        self::assertSame([], $joins);
    }

    public function test_returns_empty_array_for_invalid_sql(): void
    {
        $sql = 'NOT A VALID SQL QUERY';

        $joins = $this->extractor->extractJoins($sql);

        self::assertSame([], $joins);
    }

    // ========================================================================
    // normalizeQuery() Tests - Critical method used by 7 analyzers
    // ========================================================================

    public function test_normalize_query_replaces_string_literals(): void
    {
        // Given: Query with string literals
        $sql = "SELECT * FROM users WHERE name = 'John' AND email = 'john@example.com'";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: String literals should be replaced with ? (output is UPPERCASE)
        self::assertStringContainsString('NAME = ?', $normalized);
        self::assertStringContainsString('EMAIL = ?', $normalized);
        self::assertStringNotContainsString('John', $normalized);
        self::assertStringNotContainsString('john@example.com', $normalized);
    }

    public function test_normalize_query_replaces_numeric_literals(): void
    {
        // Given: Query with numeric literals
        $sql = 'SELECT * FROM users WHERE id = 123 AND age > 25 AND score = 98.5';

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: Numeric literals should be replaced with ? (output is UPPERCASE)
        self::assertStringContainsString('ID = ?', $normalized);
        self::assertStringContainsString('AGE > ?', $normalized);
        self::assertStringContainsString('SCORE = ?', $normalized);
        self::assertStringNotContainsString('123', $normalized);
        self::assertStringNotContainsString('25', $normalized);
        self::assertStringNotContainsString('98.5', $normalized);
    }

    public function test_normalize_query_handles_in_clause(): void
    {
        // Given: Query with IN clause
        $sql = "SELECT * FROM users WHERE id IN (1, 2, 3, 4, 5)";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: IN clause should be normalized to IN (?)
        self::assertStringContainsString('IN (?)', $normalized);
        self::assertStringNotContainsString('1, 2, 3, 4, 5', $normalized);
    }

    public function test_normalize_query_normalizes_whitespace(): void
    {
        // Given: Query with irregular whitespace
        $sql = "SELECT  *  FROM   users    WHERE  id   =   ?";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: Whitespace should be normalized (single spaces, UPPERCASE)
        self::assertStringNotContainsString('  ', $normalized); // No double spaces
        self::assertSame('SELECT * FROM USERS WHERE ID = ?', $normalized);
    }

    public function test_normalize_query_handles_update_statements(): void
    {
        // Given: UPDATE query with literals
        $sql = "UPDATE users SET name = 'John', age = 30 WHERE id = 5";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: All values should be normalized (output is UPPERCASE)
        self::assertStringContainsString('UPDATE USERS SET', $normalized);
        self::assertStringContainsString('NAME = ?', $normalized);
        self::assertStringContainsString('AGE = ?', $normalized);
        self::assertStringContainsString('WHERE ID = ?', $normalized);
        self::assertStringNotContainsString('John', $normalized);
        self::assertStringNotContainsString('30', $normalized);
    }

    public function test_normalize_query_handles_delete_statements(): void
    {
        // Given: DELETE query with literals
        $sql = "DELETE FROM users WHERE age > 100 AND status = 'inactive'";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: All values should be normalized (output is UPPERCASE)
        self::assertStringContainsString('DELETE FROM USERS WHERE', $normalized);
        self::assertStringContainsString('AGE > ?', $normalized);
        self::assertStringContainsString('STATUS = ?', $normalized);
        self::assertStringNotContainsString('100', $normalized);
        self::assertStringNotContainsString('inactive', $normalized);
    }

    public function test_normalize_query_handles_complex_select_with_joins(): void
    {
        // Given: Complex query with JOINs and multiple conditions
        $sql = "SELECT u.*, o.total FROM users u
                LEFT JOIN orders o ON u.id = o.user_id
                WHERE u.created_at > '2024-01-01' AND o.status = 'completed'
                AND o.total > 100";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: Structure preserved, values normalized (output is UPPERCASE)
        self::assertStringContainsString('LEFT JOIN ORDERS', $normalized);
        self::assertStringContainsString('U.ID = ?', $normalized); // JOIN conditions also normalized
        self::assertStringContainsString('CREATED_AT > ?', $normalized);
        self::assertStringContainsString('STATUS = ?', $normalized);
        self::assertStringContainsString('TOTAL > ?', $normalized);
        self::assertStringNotContainsString('2024-01-01', $normalized);
        self::assertStringNotContainsString('completed', $normalized);
        self::assertStringNotContainsString('100', $normalized);
    }

    public function test_normalize_query_preserves_parameterized_queries(): void
    {
        // Given: Already parameterized query
        $sql = 'SELECT * FROM users WHERE id = ? AND name = ?';

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: Placeholders should be preserved (output is UPPERCASE)
        self::assertStringContainsString('ID = ?', $normalized);
        self::assertStringContainsString('NAME = ?', $normalized);
    }

    public function test_normalize_query_handles_multiple_in_clauses(): void
    {
        // Given: Query with multiple IN clauses
        $sql = "SELECT * FROM orders WHERE status IN ('pending', 'processing')
                AND user_id IN (1, 2, 3)";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: Both IN clauses normalized
        // Count occurrences of "IN (?)"
        $count = substr_count($normalized, 'IN (?)');
        self::assertGreaterThanOrEqual(2, $count);
        self::assertStringNotContainsString('pending', $normalized);
        self::assertStringNotContainsString('1, 2, 3', $normalized);
    }

    public function test_normalize_query_handles_string_with_escaped_quotes(): void
    {
        // Given: Query with escaped quotes in string
        $sql = "SELECT * FROM users WHERE bio = 'It\\'s a beautiful day'";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: String should be replaced with ? (output is UPPERCASE)
        self::assertStringContainsString('BIO = ?', $normalized);
        self::assertStringNotContainsString("It\\'s", $normalized);
    }

    public function test_normalize_query_handles_case_insensitivity(): void
    {
        // Given: Query with mixed case keywords
        $sql = "select * from users where id = 123";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: Should normalize regardless of case (output is UPPERCASE)
        self::assertStringContainsString('SELECT', $normalized);
        self::assertStringContainsString('FROM', $normalized);
        self::assertStringContainsString('WHERE', $normalized);
        self::assertStringContainsString('ID = ?', $normalized);
    }

    public function test_normalize_query_groups_identical_patterns(): void
    {
        // Given: Two queries that should normalize to same pattern
        $sql1 = "SELECT * FROM users WHERE id = 123";
        $sql2 = "SELECT * FROM users WHERE id = 456";

        // When: We normalize both queries
        $normalized1 = $this->extractor->normalizeQuery($sql1);
        $normalized2 = $this->extractor->normalizeQuery($sql2);

        // Then: Should produce identical normalized patterns
        self::assertSame($normalized1, $normalized2);
    }

    public function test_normalize_query_falls_back_to_regex_for_invalid_sql(): void
    {
        // Given: Invalid SQL that parser can't handle
        $sql = "SOME WEIRD QUERY THAT LOOKS LIKE SQL WITH 123 AND 'string'";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: Should still normalize using regex fallback
        // At minimum, whitespace should be normalized
        self::assertIsString($normalized);
        self::assertNotEmpty($normalized);
    }

    public function test_normalize_query_handles_subqueries(): void
    {
        // Given: Query with subquery
        $sql = "SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE total > 100)";

        // When: We normalize the query
        $normalized = $this->extractor->normalizeQuery($sql);

        // Then: Subquery is normalized to IN (?) - subqueries are collapsed
        self::assertStringContainsString('SELECT', $normalized);
        self::assertStringContainsString('IN (?)', $normalized);
        self::assertStringNotContainsString('100', $normalized);
    }

    // ========================================================================
    // extractAggregationFunctions() Tests
    // ========================================================================

    public function test_extracts_count_function(): void
    {
        $sql = 'SELECT COUNT(id) FROM orders';

        $aggregations = $this->extractor->extractAggregationFunctions($sql);

        self::assertContains('COUNT', $aggregations);
        self::assertCount(1, $aggregations);
    }

    public function test_extracts_multiple_aggregation_functions(): void
    {
        $sql = 'SELECT COUNT(id), SUM(total), AVG(price) FROM orders';

        $aggregations = $this->extractor->extractAggregationFunctions($sql);

        self::assertContains('COUNT', $aggregations);
        self::assertContains('SUM', $aggregations);
        self::assertContains('AVG', $aggregations);
        self::assertCount(3, $aggregations);
    }

    public function test_extracts_min_max_functions(): void
    {
        $sql = 'SELECT MIN(price), MAX(price) FROM products';

        $aggregations = $this->extractor->extractAggregationFunctions($sql);

        self::assertContains('MIN', $aggregations);
        self::assertContains('MAX', $aggregations);
        self::assertCount(2, $aggregations);
    }

    public function test_returns_empty_array_when_no_aggregations(): void
    {
        $sql = 'SELECT id, name FROM users';

        $aggregations = $this->extractor->extractAggregationFunctions($sql);

        self::assertSame([], $aggregations);
    }

    public function test_aggregation_returns_empty_array_for_non_select_query(): void
    {
        $sql = 'UPDATE orders SET status = ? WHERE id = ?';

        $aggregations = $this->extractor->extractAggregationFunctions($sql);

        self::assertSame([], $aggregations);
    }

    // ========================================================================
    // findIsNotNullFieldOnAlias() Tests
    // ========================================================================

    public function test_finds_is_not_null_field_on_alias(): void
    {
        $sql = 'SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id WHERE o.status IS NOT NULL';

        $fieldName = $this->extractor->findIsNotNullFieldOnAlias($sql, 'o');

        self::assertSame('status', $fieldName);
    }

    public function test_returns_null_when_no_is_not_null_condition(): void
    {
        $sql = 'SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id WHERE o.status = ?';

        $fieldName = $this->extractor->findIsNotNullFieldOnAlias($sql, 'o');

        self::assertNull($fieldName);
    }

    public function test_returns_null_when_alias_not_found(): void
    {
        $sql = 'SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id WHERE o.status IS NOT NULL';

        $fieldName = $this->extractor->findIsNotNullFieldOnAlias($sql, 'x');

        self::assertNull($fieldName);
    }

    public function test_finds_is_not_null_without_join(): void
    {
        $sql = 'SELECT * FROM users u WHERE u.email IS NOT NULL';

        $fieldName = $this->extractor->findIsNotNullFieldOnAlias($sql, 'u');

        self::assertSame('email', $fieldName);
    }

    public function test_finds_first_is_not_null_field_when_multiple(): void
    {
        $sql = 'SELECT * FROM users u WHERE u.email IS NOT NULL AND u.name IS NOT NULL';

        $fieldName = $this->extractor->findIsNotNullFieldOnAlias($sql, 'u');

        // Should return first match
        self::assertSame('email', $fieldName);
    }

    public function test_handles_case_insensitive_is_not_null(): void
    {
        $sql = 'SELECT * FROM users u WHERE u.email is not null';

        $fieldName = $this->extractor->findIsNotNullFieldOnAlias($sql, 'u');

        self::assertSame('email', $fieldName);
    }
}
