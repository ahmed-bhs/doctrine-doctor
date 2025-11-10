<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Helper;

/**
 * Extracts column names from SQL queries for index suggestions.
 * Handles WHERE, JOIN, and ORDER BY clauses.
 */
final class QueryColumnExtractor
{
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
     * @return array<string>
     */
    private function extractWhereColumns(string $sql, string $targetTable): array
    {
        $pattern = '/(?:WHERE|AND|OR)\s+(?:' . preg_quote($targetTable, '/') . '\.)?`?(\w+)`?\s*(?:[=<>!]|LIKE|IN|IS|BETWEEN)/i';

        if (preg_match_all($pattern, $sql, $matches) < 1) {
            return [];
        }

        return $this->filterSqlKeywords($matches[1]);
    }

    /**
     * Extract columns from JOIN ON conditions.
     * @return array<string>
     */
    private function extractJoinColumns(string $sql, string $targetTable): array
    {
        $pattern = '/ON\s+(?:' . preg_quote($targetTable, '/') . '\.)?`?(\w+)`?\s*=/i';

        if (preg_match_all($pattern, $sql, $matches) < 1) {
            return [];
        }

        return $this->filterSqlKeywords($matches[1]);
    }

    /**
     * Extract columns from ORDER BY clause.
     * @return array<string>
     */
    private function extractOrderByColumns(string $sql, string $targetTable): array
    {
        $pattern = '/ORDER\s+BY\s+(?:' . preg_quote($targetTable, '/') . '\.)?`?(\w+)`?/i';

        if (preg_match_all($pattern, $sql, $matches) < 1) {
            return [];
        }

        return $this->filterSqlKeywords($matches[1]);
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

        assert(is_iterable($columns), '$columns must be iterable');

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

        assert(is_iterable($columns), '$columns must be iterable');

        foreach ($columns as $column) {
            if (!in_array($column, $unique, true)) {
                $unique[] = $column;
            }
        }

        return $unique;
    }
}
