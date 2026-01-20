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

/**
 * Detects QueryBuilder anti-patterns and best practice violations.
 *
 * This class combines SQL parser and pattern matching to detect:
 * - String concatenation (SQL injection risk)
 * - Incorrect NULL comparisons
 * - Empty IN() clauses
 * - Unescaped LIKE patterns
 * - Missing parameters
 *
 * Using both parser (when possible) and regex (when necessary) for maximum reliability.
 */
class QueryBuilderPatternDetector
{
    public function __construct(
        private SqlStructureExtractor $sqlExtractor = new SqlStructureExtractor(),
    ) {
    }

    /**
     * Detects potential SQL injection via string concatenation.
     *
     * Patterns detected:
     * - WHERE field = "value" (should be WHERE field = :param)
     * - WHERE field = 'value' (should be WHERE field = :param)
     * - AND/OR with quoted literals
     *
     * This is a heuristic - might flag legitimate cases but better safe than sorry.
     *
     * @return array{detected: bool, locations: string[]}
     */
    public function detectPotentialSqlInjection(string $sql): array
    {
        $locations = [];

        // Use SQL parser to extract WHERE conditions
        $conditions = $this->sqlExtractor->extractWhereConditions($sql);

        foreach ($conditions as $condition) {
            $column = $condition['column'];
            $operator = $condition['operator'];

            // Now check the full SQL to see if this condition uses literals
            if ($this->hasLiteralValueInCondition($sql, $column, $operator)) {
                $locations[] = sprintf('%s %s <literal>', $column, $operator);
            }
        }

        return [
            'detected' => [] !== $locations,
            'locations' => $locations,
        ];
    }

    /**
     * Detects WHERE/AND/OR with quoted literals (comprehensive check).
     *
     * Fallback method for cases where parser doesn't catch everything.
     *
     * @return string[] Patterns found
     */
    public function detectQuotedLiteralsInConditions(string $sql): array
    {
        $patterns = [
            'WHERE with double quotes' => '/WHERE\s+\w+\s*=\s*"[^"]*"/',
            'WHERE with single quotes' => '/WHERE\s+\w+\s*=\s*\'[^\']*\'/',
            'AND with double quotes' => '/AND\s+\w+\s*=\s*"[^"]*"/',
            'AND with single quotes' => '/AND\s+\w+\s*=\s*\'[^\']*\'/',
            'OR with double quotes' => '/OR\s+\w+\s*=\s*"[^"]*"/',
            'OR with single quotes' => '/OR\s+\w+\s*=\s*\'[^\']*\'/',
        ];

        $found = [];
        foreach ($patterns as $name => $pattern) {
            if (1 === preg_match($pattern, $sql)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * Detects incorrect NULL comparisons using SQL parser.
     *
     * Patterns:
     * - field = NULL (should be: field IS NULL)
     * - field != NULL (should be: field IS NOT NULL)
     * - field <> NULL (should be: field IS NOT NULL)
     *
     * @return array{detected: bool, fields: string[]}
     */
    public function detectIncorrectNullComparison(string $sql): array
    {
        $conditions = $this->sqlExtractor->extractWhereConditions($sql);
        $incorrectFields = [];

        foreach ($conditions as $condition) {
            $column = $condition['column'];
            $operator = $condition['operator'];

            // Check if comparing with NULL using = or !=
            if (in_array($operator, ['=', '!=', '<>'], true)) {
                // Look for NULL in the SQL after this column
                if ($this->hasNullComparisonWithOperator($sql, $column, $operator)) {
                    $incorrectFields[] = sprintf('%s %s NULL', $column, $operator);
                }
            }
        }

        return [
            'detected' => [] !== $incorrectFields,
            'fields' => $incorrectFields,
        ];
    }

    /**
     * Detects empty IN() clause.
     *
     * Pattern: IN () - causes SQL syntax error
     *
     * This is critical and should be caught before query execution.
     */
    public function hasEmptyInClause(string $sql): bool
    {
        return 1 === preg_match('/IN\s*\(\s*\)/i', $sql);
    }

    /**
     * Detects LIKE with unescaped wildcards.
     *
     * Pattern: LIKE '%value%' in SQL (should be parameterized)
     *
     * If wildcards are in the SQL itself, it indicates concatenation
     * instead of proper parameterization.
     */
    public function hasUnescapedLike(string $sql): bool
    {
        // First check if LIKE is present
        if (!str_contains(strtoupper($sql), 'LIKE')) {
            return false;
        }

        // Look for LIKE with quoted wildcards: LIKE '%something%' or LIKE "_test%"
        return 1 === preg_match('/LIKE\s+[\'"][%_].*[%_]*[\'"]/', $sql);
    }

    /**
     * Extracts parameter placeholders from SQL.
     *
     * Returns array of parameter names used in the query.
     *
     * Example: ":userId AND :status" → ['userId', 'status']
     *
     * @return string[]
     */
    public function extractParameterPlaceholders(string $sql): array
    {
        if (preg_match_all('/:(\w+)/', $sql, $matches) < 1) {
            return [];
        }

        return array_unique($matches[1]);
    }

    /**
     * Checks if any parameter placeholders are missing from provided params.
     *
     * @param array<string, mixed> $providedParams
     * @return array{hasMissing: bool, missing: string[]}
     */
    public function detectMissingParameters(string $sql, array $providedParams): array
    {
        $placeholders = $this->extractParameterPlaceholders($sql);

        if ([] === $placeholders) {
            return ['hasMissing' => false, 'missing' => []];
        }

        $missing = [];
        foreach ($placeholders as $placeholder) {
            if (!array_key_exists($placeholder, $providedParams)) {
                $missing[] = $placeholder;
            }
        }

        return [
            'hasMissing' => [] !== $missing,
            'missing' => $missing,
        ];
    }

    /**
     * Provides a descriptive message for a detected pattern.
     */
    public function getPatternDescription(string $patternType): string
    {
        return match ($patternType) {
            'sql_injection' => 'String concatenation in WHERE/AND/OR - use parameters instead',
            'incorrect_null' => 'Use IS NULL / IS NOT NULL instead of = NULL / != NULL',
            'empty_in' => 'Empty IN() clause will cause SQL syntax error',
            'unescaped_like' => 'LIKE with wildcards in SQL - should be parameterized and escaped',
            'missing_params' => 'Parameter placeholders without corresponding setParameter() calls',
            default => 'Unknown pattern',
        };
    }

    /**
     * Provides fix suggestions for a detected pattern.
     */
    public function getFixSuggestion(string $patternType): string
    {
        return match ($patternType) {
            'sql_injection' => 'Use ->setParameter(\'name\', $value) instead of concatenating values into the query',
            'incorrect_null' => 'Use $qb->expr()->isNull(\'field\') or $qb->expr()->isNotNull(\'field\')',
            'empty_in' => 'Check if array is empty before using IN(): if ($ids !== []) { ... }',
            'unescaped_like' => 'Escape wildcards in user input: addcslashes($value, \'%_\')',
            'missing_params' => 'Add missing ->setParameter() calls for all placeholders',
            default => 'Review the query and fix the issue',
        };
    }

    /**
     * Checks if a specific condition uses literal values instead of parameters.
     */
    private function hasLiteralValueInCondition(string $sql, string $column, string $operator): bool
    {
        $escapedColumn = preg_quote($column, '/');
        $escapedOperator = preg_quote($operator, '/');

        // Pattern: column operator "value" or column operator 'value'
        $pattern = sprintf('/%s\s*%s\s*["\'][^"\']*["\']/', $escapedColumn, $escapedOperator);

        return 1 === preg_match($pattern, $sql);
    }

    /**
     * Checks if a column has NULL comparison with wrong operator.
     */
    private function hasNullComparisonWithOperator(string $sql, string $column, string $operator): bool
    {
        $escapedColumn = preg_quote($column, '/');
        $escapedOperator = preg_quote($operator, '/');

        // Pattern: column operator NULL
        $pattern = sprintf('/%s\s*%s\s*NULL/i', $escapedColumn, $escapedOperator);

        return 1 === preg_match($pattern, $sql);
    }
}
