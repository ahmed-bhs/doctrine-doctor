<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser\Interface;

/**
 * Interface for analyzing SQL conditions (WHERE, ON clauses).
 *
 * Provides methods to extract and analyze conditions from SQL queries.
 */
interface ConditionAnalyzerInterface
{
    /**
     * Extracts columns from WHERE clause that could benefit from indexing.
     *
     * @return array<string> Column names found in WHERE conditions
     */
    public function extractWhereColumns(string $sql): array;

    /**
     * Extracts WHERE conditions with their column names and operators.
     * Returns array of conditions like: ['column' => 'user_id', 'operator' => '=']
     *
     * @return array<int, array{column: string, operator: string, alias: ?string}>
     */
    public function extractWhereConditions(string $sql): array;

    /**
     * Extracts columns from JOIN conditions that could benefit from indexing.
     *
     * @return array<string> Column names found in JOIN ON clauses
     */
    public function extractJoinColumns(string $sql): array;

    /**
     * Extracts date/time functions used in WHERE clauses with comparisons.
     *
     * Detects patterns like:
     * - YEAR(created_at) = 2023
     * - MONTH(order_date) >= 12
     * - DATE(timestamp_field) != '2023-01-01'
     *
     * @return array<int, array{function: string, field: string, operator: string, value: string, raw: string}>
     *
     * Example return:
     * [
     *     ['function' => 'YEAR', 'field' => 'created_at', 'operator' => '=', 'value' => '2023', 'raw' => 'YEAR(created_at) = 2023'],
     *     ['function' => 'MONTH', 'field' => 'order_date', 'operator' => '>=', 'value' => '12', 'raw' => 'MONTH(order_date) >= 12'],
     * ]
     */
    public function extractFunctionsInWhere(string $sql): array;

    /**
     * Checks if a specific table alias has IS NOT NULL condition in WHERE clause.
     *
     * Used to detect redundant LEFT JOINs (LEFT JOIN + IS NOT NULL = should be INNER JOIN).
     *
     * Example:
     * - SQL: "SELECT * FROM users u LEFT JOIN orders o WHERE o.status IS NOT NULL"
     * - findIsNotNullFieldOnAlias(sql, 'o') → 'status'
     *
     * @param string $sql The SQL query
     * @param string $alias The table alias to check
     * @return string|null Field name if IS NOT NULL found, null otherwise
     */
    public function findIsNotNullFieldOnAlias(string $sql, string $alias): ?string;

    /**
     * Checks if SQL query has complex WHERE conditions (multiple conditions with AND/OR).
     * Simple condition: WHERE id = ?
     * Complex conditions: WHERE id = ? AND status = ?, WHERE id = ? OR name = ?
     *
     * @return bool True if query has multiple WHERE conditions, false otherwise
     */
    public function hasComplexWhereConditions(string $sql): bool;

    /**
     * Detects if JOIN has locale constraint that guarantees single row per entity.
     * Common patterns:
     * - AND (t1_.locale = ?)
     * - AND t1_.locale = ?
     *
     * These patterns prevent false positives for translation tables.
     */
    public function hasLocaleConstraintInJoin(string $sql): bool;

    /**
     * Detects if JOIN is a simple one-to-one relationship (unique constraint).
     * Pattern: JOIN table ON id = foreign_id (with exactly one condition).
     *
     * Note: This is conservative and may not catch all cases.
     */
    public function hasUniqueJoinConstraint(string $sql): bool;

    /**
     * Checks if a table/column alias is used in the query (outside of its JOIN definition).
     *
     * Used to detect unused JOINs - if you JOIN a table but never reference it,
     * the JOIN is wasted.
     *
     * Example:
     * SQL: "SELECT o.id FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.status = 'active'"
     * - isAliasUsed('o') → true (used in SELECT and WHERE)
     * - isAliasUsed('u') → false (joined but never referenced)
     *
     * @param string $sql The full SQL query
     * @param string $alias The alias to check (e.g., 'u')
     * @param string|null $joinExpression Optional JOIN expression to exclude from search
     * @return bool True if alias is used, false otherwise
     */
    public function isAliasUsedInQuery(string $sql, string $alias, ?string $joinExpression = null): bool;
}
