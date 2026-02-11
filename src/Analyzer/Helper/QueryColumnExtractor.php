<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Helper;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use Webmozart\Assert\Assert;

/**
 * Extracts column names from SQL queries for index suggestions.
 * Handles WHERE, JOIN, and ORDER BY clauses.
 *
 * Migration from regex to SQL Parser:
 * - Replaced 3 regex patterns with SqlStructureExtractor methods
 * - More robust parsing (handles subqueries, complex SQL)
 * - Avoids false positives from SQL keywords
 */
final readonly class QueryColumnExtractor
{
    private SqlStructureExtractor $sqlExtractor;

    public function __construct(?SqlStructureExtractor $sqlExtractor = null)
    {
        $this->sqlExtractor = $sqlExtractor ?? new SqlStructureExtractor();
    }

    /**
     * Extract columns from query that could benefit from indexing.
     * @return array<string>
     */
    public function extractColumns(string $sql, string $targetTable): array
    {
        $columns = [];

        // Extract columns from different parts of the query
        $columns = array_merge($columns, $this->extractWhereColumns($sql, $targetTable));
        $columns = array_merge($columns, $this->extractJoinColumns($sql, $targetTable));

        // ORDER BY columns are added at the beginning (better index candidates)
        $orderByColumns = $this->extractOrderByColumns($sql, $targetTable);
        $columns        = array_merge($orderByColumns, $columns);

        // Remove duplicates and limit to 3 columns
        return array_slice($this->removeDuplicateColumns($columns), 0, 3);
    }

    /**
     * Extract columns from WHERE clause.
     * Uses SQL Parser instead of regex for robust extraction.
     * @return array<string>
     */
    private function extractWhereColumns(string $sql, string $targetTable): array
    {
        $columns = $this->sqlExtractor->extractWhereColumns($sql);

        // Filter table-specific columns if needed
        // Note: SqlStructureExtractor extracts all columns without table prefix
        // The regex used to optionally match table prefix, so we do the same
        return $this->filterSqlKeywords($columns);
    }

    /**
     * Extract columns from JOIN ON conditions.
     * Uses SQL Parser instead of regex for robust extraction.
     * @return array<string>
     */
    private function extractJoinColumns(string $sql, string $targetTable): array
    {
        $columns = $this->sqlExtractor->extractJoinColumns($sql);

        return $this->filterSqlKeywords($columns);
    }

    /**
     * Extract columns from ORDER BY clause.
     * Uses SQL Parser instead of regex for robust extraction.
     * @return array<string>
     */
    private function extractOrderByColumns(string $sql, string $targetTable): array
    {
        $columns = $this->sqlExtractor->extractOrderByColumnNames($sql);

        return $this->filterSqlKeywords($columns);
    }

    /**
     * Filter out SQL keywords from column names.
     * @param array<string> $columns
     * @return array<string>
     */
    private function filterSqlKeywords(array $columns): array
    {
        $sqlKeywords = [
            'WHERE', 'AND', 'OR', 'SELECT', 'FROM', 'JOIN', 'ON', 'ORDER', 'BY',
            'ASC', 'DESC', 'LIKE', 'IN', 'IS', 'NULL', 'NOT', 'INNER', 'LEFT',
            'RIGHT', 'OUTER', 'GROUP', 'HAVING', 'LIMIT', 'OFFSET', 'AS', 'CASE',
            'WHEN', 'THEN', 'ELSE', 'END', 'DISTINCT', 'ALL', 'BETWEEN', 'EXISTS',
        ];

        $filtered = [];

        Assert::isIterable($columns, '$columns must be iterable');

        foreach ($columns as $column) {
            if (!in_array(strtoupper($column), $sqlKeywords, true)) {
                $filtered[] = strtolower($column);
            }
        }

        return $filtered;
    }

    /**
     * Remove duplicate columns while preserving order.
     * @param array<string> $columns
     * @return array<string>
     */
    private function removeDuplicateColumns(array $columns): array
    {
        $unique = [];

        Assert::isIterable($columns, '$columns must be iterable');

        foreach ($columns as $column) {
            if (!in_array($column, $unique, true)) {
                $unique[] = $column;
            }
        }

        return $unique;
    }
}
