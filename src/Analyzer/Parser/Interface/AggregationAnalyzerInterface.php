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
 * Interface for analyzing SQL aggregation functions and related clauses.
 *
 * Provides methods to extract aggregation functions, GROUP BY, ORDER BY, and SELECT clauses.
 */
interface AggregationAnalyzerInterface
{
    /**
     * Extracts aggregation functions from SELECT clause.
     *
     * Returns array of aggregation functions found (COUNT, SUM, AVG, MIN, MAX).
     * Used to detect potential row duplication issues with JOINs.
     *
     * Example:
     * - "SELECT COUNT(o.id), SUM(o.total) FROM orders" → ['COUNT', 'SUM']
     * - "SELECT o.*, p.name FROM orders" → []
     *
     * @return array<string> List of aggregation function names (uppercase)
     */
    public function extractAggregationFunctions(string $sql): array;

    /**
     * Extracts GROUP BY columns from query.
     *
     * Useful for suggesting indexes on these columns.
     *
     * @return string[] Array of column names
     */
    public function extractGroupByColumns(string $sql): array;

    /**
     * Extracts the ORDER BY clause from a SQL query.
     *
     * @return string|null The ORDER BY clause (e.g., "created_at DESC, id ASC") or null if not present
     */
    public function extractOrderBy(string $sql): ?string;

    /**
     * Extracts columns from ORDER BY clause.
     *
     * @return array<string> Column names found in ORDER BY
     */
    public function extractOrderByColumnNames(string $sql): array;

    /**
     * Extracts the SELECT clause content (everything between SELECT and FROM).
     * Returns the select clause string or null if not found.
     */
    public function extractSelectClause(string $sql): ?string;

    /**
     * Extracts table aliases from SELECT clause.
     * Doctrine generates aliases like: t0_.column, t1_.column, etc.
     * Returns array of unique aliases (e.g., ['t0', 't1']).
     *
     * @return string[]
     */
    public function extractTableAliasesFromSelect(string $sql): array;
}
