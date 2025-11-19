<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Interface\ConditionAnalyzerInterface;
use PhpMyAdmin\SqlParser\Components\Condition;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;

/**
 * Analyzes SQL conditions (WHERE, ON clauses).
 *
 * This class focuses solely on condition analysis,
 * following the Single Responsibility Principle.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
final class SqlConditionAnalyzer implements ConditionAnalyzerInterface
{
    public function extractWhereColumns(string $sql): array
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return [];
        }

        if (null === $statement->where || [] === $statement->where) {
            return [];
        }

        $columns = [];
        foreach ($statement->where as $condition) {
            if (!$condition instanceof Condition) {
                continue;
            }

            // Extract column names from condition expressions
            $expr = trim((string) $condition->expr);

            // Simple extraction: find column names (word characters before operators)
            if (preg_match_all('/\b(\w+)\s*(?:=|<|>|<=|>=|!=|<>|LIKE|IN|IS|BETWEEN)/i', $expr, $matches) > 0) {
                foreach ($matches[1] as $col) {
                    $columns[] = strtolower($col);
                }
            }
        }

        return array_values(array_unique($columns));
    }

    public function extractWhereConditions(string $sql): array
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return [];
        }

        if (null === $statement->where || [] === $statement->where) {
            return [];
        }

        $conditions = [];

        foreach ($statement->where as $token) {
            if (!$token instanceof Condition) {
                continue;
            }

            $expr = trim((string) $token->expr);

            // Extract column name and operator
            // Pattern: alias.column = ? or column = ?
            if (preg_match('/(?:(\w+)\.)?(\w+)\s*(=|!=|<>|>|<|>=|<=|IN|LIKE|IS)/i', $expr, $matches) > 0) {
                $conditions[] = [
                    'alias' => '' !== $matches[1] ? $matches[1] : null,
                    'column' => $matches[2],
                    'operator' => strtoupper($matches[3]),
                ];
            }
        }

        return $conditions;
    }

    public function extractJoinColumns(string $sql): array
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return [];
        }

        if (null === $statement->join || [] === $statement->join) {
            return [];
        }

        $columns = [];
        foreach ($statement->join as $join) {
            if (null === $join->on) {
                continue;
            }

            foreach ($join->on as $condition) {
                if (!$condition instanceof Condition) {
                    continue;
                }

                $expr = trim((string) $condition->expr);

                // Extract columns from ON conditions
                if (preg_match_all('/\b(\w+)\s*=/', $expr, $matches) > 0) {
                    foreach ($matches[1] as $col) {
                        $columns[] = strtolower($col);
                    }
                }
            }
        }

        return array_values(array_unique($columns));
    }

    public function extractFunctionsInWhere(string $sql): array
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return [];
        }

        if (null === $statement->where || [] === $statement->where) {
            return [];
        }

        $functions = [];
        $dateTimeFunctions = ['YEAR', 'MONTH', 'DAY', 'DATE', 'HOUR', 'MINUTE', 'SECOND'];

        // Iterate through WHERE conditions
        foreach ($statement->where as $condition) {
            if (!$condition instanceof Condition) {
                continue;
            }

            // Parse the condition expression to find function calls
            $extracted = $this->extractFunctionFromCondition($condition, $dateTimeFunctions);
            if (null !== $extracted) {
                $functions[] = $extracted;
            }
        }

        return $functions;
    }

    public function findIsNotNullFieldOnAlias(string $sql, string $alias): ?string
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return null;
        }

        // Extract WHERE conditions
        $where = $statement->where ?? [];

        foreach ($where as $condition) {
            $condStr = (string) $condition;

            // Check for pattern: alias.field IS NOT NULL
            // Use word boundaries to ensure exact alias match
            $pattern = '/\b' . preg_quote($alias, '/') . '\.(\w+)\s+IS\s+NOT\s+NULL/i';
            if (1 === preg_match($pattern, $condStr, $matches)) {
                return $matches[1]; // Return field name
            }
        }

        return null;
    }

    public function hasComplexWhereConditions(string $sql): bool
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return false;
        }

        if (null === $statement->where || [] === $statement->where) {
            return false;
        }

        // Count actual conditions (not counting keywords like AND, OR)
        $conditionCount = 0;

        foreach ($statement->where as $token) {
            if ($token instanceof Condition) {
                ++$conditionCount;
            }
        }

        // If more than 1 condition, it's complex (has AND/OR between them)
        return $conditionCount > 1;
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function hasLocaleConstraintInJoin(string $sql): bool
    {
        $sqlUpper = strtoupper($sql);

        // Pattern 1: Translation pattern with locale filter in parentheses
        // Example: INNER JOIN translation t1_ ON ... AND (t1_.LOCALE = ?)
        if (str_contains($sqlUpper, 'JOIN') && str_contains($sqlUpper, 'AND')) {
            // Use parser to check JOIN conditions
            $parser = new Parser($sql);
            $statement = $parser->statements[0] ?? null;

            if (!$statement instanceof SelectStatement) {
                return false;
            }

            if (null === $statement->join || [] === $statement->join) {
                return false;
            }

            foreach ($statement->join as $join) {
                if (null === $join->on || [] === $join->on) {
                    continue;
                }

                // Check if any ON condition mentions LOCALE
                foreach ($join->on as $condition) {
                    $condStr = (string) $condition;
                    if ('' !== $condStr) {
                        $conditionStr = strtoupper($condStr);
                        if (str_contains($conditionStr, 'LOCALE') && (str_contains($conditionStr, '=') || str_contains($conditionStr, 'IN'))) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    public function hasUniqueJoinConstraint(string $sql): bool
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return false;
        }

        if (null === $statement->join || [] === $statement->join) {
            return false;
        }

        // Check each JOIN for simple ID equality pattern
        foreach ($statement->join as $join) {
            if (null === $join->on || [] === $join->on) {
                continue;
            }

            // Count conditions in this JOIN's ON clause
            $conditionCount = count($join->on);

            // Simple case: exactly one condition
            if (1 === $conditionCount) {
                $conditionStr = strtoupper((string) $join->on[0]);

                // Check if it's an ID = ID pattern (potential one-to-one)
                if (str_contains($conditionStr, '.ID') && str_contains($conditionStr, '=')) {
                    // This might be one-to-one, but we can't be certain
                    // For now, return false to be conservative (flag as potentially problematic)
                    // A more sophisticated check would query metadata
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function isAliasUsedInQuery(string $sql, string $alias, ?string $joinExpression = null): bool
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return false;
        }

        // Build search pattern for alias usage: alias.column
        $aliasPattern = '/\b' . preg_quote($alias, '/') . '\b\./';

        // Check SELECT clause
        $selectExprs = $statement->expr ?? [];
        if ([] !== $selectExprs) {
            foreach ($statement->expr as $expr) {
                $exprStr = (string) $expr;
                if (1 === preg_match($aliasPattern, $exprStr)) {
                    return true;
                }
            }
        }

        // Check WHERE clause
        if (null !== $statement->where && [] !== $statement->where) {
            foreach ($statement->where as $condition) {
                $condStr = (string) $condition;
                if (1 === preg_match($aliasPattern, $condStr)) {
                    return true;
                }
            }
        }

        // Check GROUP BY clause
        if (null !== $statement->group && [] !== $statement->group) {
            foreach ($statement->group as $groupExpr) {
                $groupStr = (string) $groupExpr->expr;
                if (1 === preg_match($aliasPattern, $groupStr)) {
                    return true;
                }
            }
        }

        // Check ORDER BY clause
        if (null !== $statement->order && [] !== $statement->order) {
            foreach ($statement->order as $orderExpr) {
                $orderStr = (string) $orderExpr->expr;
                if (1 === preg_match($aliasPattern, $orderStr)) {
                    return true;
                }
            }
        }

        // Check HAVING clause
        if (null !== $statement->having && [] !== $statement->having) {
            foreach ($statement->having as $havingCondition) {
                $havingStr = (string) $havingCondition;
                if (1 === preg_match($aliasPattern, $havingStr)) {
                    return true;
                }
            }
        }

        // Check other JOINs (alias might be used in ON clause of another JOIN)
        $joins = $statement->join ?? [];
        if ([] !== $joins) {
            foreach ($joins as $join) {
                // Skip the JOIN definition we're checking
                if (null !== $joinExpression) {
                    $thisJoinTable = $join->expr->table ?? '';

                    // Skip if this is the same JOIN we're checking
                    // Use more precise matching: check if table AND potential alias match
                    if (false !== stripos($joinExpression, (string) $thisJoinTable)) {
                        // Found table name - verify it's an exact match, not substring
                        // Example: Skip "roles" matching inside "user_roles"
                        $tablePattern = '/\b' . preg_quote($thisJoinTable, '/') . '\b/i';
                        if (1 === preg_match($tablePattern, $joinExpression)) {
                            continue; // Skip this JOIN's ON clause
                        }
                    }
                }

                // Check this JOIN's ON clause
                $onConditions = $join->on ?? [];
                if ([] !== $onConditions) {
                    foreach ($onConditions as $condition) {
                        $condStr = (string) $condition;
                        if (1 === preg_match($aliasPattern, $condStr)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Extract function call information from a WHERE condition.
     *
     * @param array<string> $targetFunctions List of functions to detect (YEAR, MONTH, etc.)
     * @return array{function: string, field: string, operator: string, value: string, raw: string}|null
     */
    private function extractFunctionFromCondition(Condition $condition, array $targetFunctions): ?array
    {
        // Get the raw expression string
        $expr = trim((string) $condition->expr);

        if ('' === $expr) {
            return null;
        }

        // Pattern to match: FUNCTION(field) OPERATOR value
        // Example: YEAR(created_at) = 2023
        foreach ($targetFunctions as $functionName) {
            $pattern = sprintf(
                '/\b%s\s*\(\s*(\w+(?:\.\w+)?)\s*\)\s*(=|<>|!=|>|<|>=|<=)\s*([^\s]+)/i',
                preg_quote($functionName, '/'),
            );

            if (1 === preg_match($pattern, $expr, $matches)) {
                return [
                    'function' => strtoupper($functionName),
                    'field' => $matches[1],
                    'operator' => $matches[2],
                    'value' => trim($matches[3], "'\""),
                    'raw' => $expr,
                ];
            }
        }

        return null;
    }
}
