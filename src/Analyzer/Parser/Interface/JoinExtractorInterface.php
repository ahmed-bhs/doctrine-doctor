<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser\Interface;

/**
 * Interface for extracting JOIN information from SQL queries.
 *
 * Provides methods to extract JOINs, tables, and aliases from SQL queries.
 */
interface JoinExtractorInterface
{
    /**
     * Extracts all JOINs from a SQL query.
     *
     * @return array<int, array{type: string, table: string, alias: ?string, expr: mixed}>
     *
     * Example return:
     * [
     *     ['type' => 'LEFT', 'table' => 'orders', 'alias' => 'o', 'expr' => JoinExpression],
     *     ['type' => 'INNER', 'table' => 'products', 'alias' => 'p', 'expr' => JoinExpression],
     * ]
     */
    public function extractJoins(string $sql): array;

    /**
     * Extracts the main table from the FROM clause.
     *
     * @return array{table: string, alias: ?string}|null
     */
    public function extractMainTable(string $sql): ?array;

    /**
     * Extracts all tables (FROM + JOINs).
     *
     * @return array<int, array{table: string, alias: ?string, source: string}>
     *
     * Source can be: 'from' or 'join'
     */
    public function extractAllTables(string $sql): array;

    /**
     * Get all table names (without aliases) from a SQL query.
     * Returns table names in lowercase for case-insensitive matching.
     *
     * @return array<string> Array of table names
     */
    public function getAllTableNames(string $sql): array;

    /**
     * Checks if a specific table is used in the query (FROM or JOIN).
     * Case-insensitive check.
     */
    public function hasTable(string $sql, string $tableName): bool;

    /**
     * Checks if the SQL query contains any JOIN.
     */
    public function hasJoin(string $sql): bool;

    /**
     * Checks if SQL query contains any JOIN clauses.
     *
     * @return bool True if query has JOINs, false otherwise
     */
    public function hasJoins(string $sql): bool;

    /**
     * Counts the number of JOINs in the query.
     */
    public function countJoins(string $sql): int;

    /**
     * Extracts the ON clause conditions for a specific JOIN.
     *
     * Given a JOIN definition (e.g., "LEFT JOIN users u"), finds and returns
     * the ON clause conditions.
     *
     * Example:
     * SQL: "... LEFT JOIN users u ON u.id = o.user_id WHERE ..."
     * Returns: "u.id = o.user_id"
     *
     * @param string $sql The full SQL query
     * @param string $joinExpression The JOIN expression (e.g., "LEFT JOIN users u")
     * @return string|null The ON clause conditions, or null if not found
     */
    public function extractJoinOnClause(string $sql, string $joinExpression): ?string;

    /**
     * Extracts the real table name and alias information from a query.
     * Given an alias, finds the corresponding table name from FROM or JOIN clauses.
     *
     * @return array{realName: string, display: string, alias: string}|null
     */
    public function extractTableNameWithAlias(string $sql, string $targetAlias): ?array;

    /**
     * Extracts parsed ON conditions for a specific JOIN.
     *
     * Returns structured conditions instead of a string, making it easier to analyze
     * JOIN relationships without regex parsing.
     *
     * Example:
     * SQL: "... LEFT JOIN orders o ON u.id = o.user_id AND o.status = 'active' ..."
     * Returns:
     * [
     *     ['left' => 'u.id', 'operator' => '=', 'right' => 'o.user_id'],
     *     ['left' => 'o.status', 'operator' => '=', 'right' => "'active'"],
     * ]
     *
     * @param string $sql The full SQL query
     * @param string $tableName The joined table name to find conditions for
     * @return array<int, array{left: string, operator: string, right: string}> Array of parsed conditions
     */
    public function extractJoinOnConditions(string $sql, string $tableName): array;
}
