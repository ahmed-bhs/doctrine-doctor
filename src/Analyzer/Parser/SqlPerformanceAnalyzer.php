<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Interface\PerformanceAnalyzerInterface;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;

/**
 * Analyzes SQL performance patterns.
 *
 * This class focuses solely on performance analysis,
 * following the Single Responsibility Principle.
 */
final class SqlPerformanceAnalyzer implements PerformanceAnalyzerInterface
{
    public function hasOrderBy(string $sql): bool
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return false;
        }

        return null !== $statement->order && [] !== $statement->order;
    }

    public function hasLimit(string $sql): bool
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return false;
        }

        return null !== $statement->limit;
    }

    public function hasOffset(string $sql): bool
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return false;
        }

        // Check if OFFSET is part of LIMIT clause
        if (null !== $statement->limit) {
            $limitOffset = $statement->limit->offset ?? null;
            if (null !== $limitOffset && '' !== (string) $limitOffset) {
                return true;
            }
        }

        // Fallback: Some SQL dialects allow standalone OFFSET
        // The parser may not catch it, so use regex as backup
        return 1 === preg_match('/\bOFFSET\b/i', $sql);
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function hasSubquery(string $sql): bool
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return false;
        }

        // Check SELECT clause for subqueries
        $selectExprs = $statement->expr ?? [];
        if ([] !== $selectExprs) {
            foreach ($selectExprs as $expr) {
                if ($this->expressionContainsSubquery($expr)) {
                    return true;
                }
            }
        }

        // Check WHERE clause for subqueries
        $whereConditions = $statement->where ?? [];
        if ([] !== $whereConditions) {
            foreach ($whereConditions as $condition) {
                $conditionStr = (string) $condition;
                // Look for SELECT keyword in WHERE conditions
                if (str_contains(strtoupper($conditionStr), 'SELECT')) {
                    return true;
                }
            }
        }

        // Check FROM clause for derived tables (subqueries)
        $fromClauses = $statement->from ?? [];
        if ([] !== $fromClauses) {
            foreach ($fromClauses as $fromExpr) {
                // Derived table pattern: FROM (...subquery...) AS alias
                $exprValue = $fromExpr->expr ?? null;
                if (null !== $exprValue) {
                    $exprStr = (string) $exprValue;
                    if (str_contains($exprStr, '(') && str_contains(strtoupper($exprStr), 'SELECT')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function hasGroupBy(string $sql): bool
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return false;
        }

        return null !== $statement->group && [] !== $statement->group;
    }

    public function hasLeadingWildcardLike(string $sql): bool
    {
        // Parser doesn't expose LIKE parameters clearly, use lightweight regex
        // This is appropriate: detecting specific pattern within valid SQL
        return 1 === preg_match('/LIKE\s+[\'"]%/i', $sql);
    }

    public function hasDistinct(string $sql): bool
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return false;
        }

        // Check for DISTINCT in SELECT options (SELECT DISTINCT ...)
        $statementOptions = $statement->options ?? null;
        if (null !== $statementOptions) {
            $options = $statementOptions->options ?? [];
            if ([] !== $options) {
                foreach ($options as $option) {
                    if (is_string($option)) {
                        $optionStr = $option;
                        if ('' !== $optionStr && 'DISTINCT' === strtoupper(trim($optionStr))) {
                            return true;
                        }
                    }
                }
            }
        }

        // Check for DISTINCT in aggregation functions (COUNT(DISTINCT ...), etc.)
        $selectExpressions = $statement->expr ?? [];
        foreach ($selectExpressions as $expr) {
            if ($expr instanceof Expression) {
                // Look for DISTINCT inside function calls
                $exprStr = (string) $expr;
                if (1 === preg_match('/\b(COUNT|SUM|AVG|MIN|MAX)\s*\(\s*DISTINCT/i', $exprStr)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getLimitValue(string $sql): ?int
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return null;
        }

        if (null === $statement->limit) {
            return null;
        }

        // LIMIT clause can be in format "LIMIT offset, row_count" or "LIMIT row_count"
        // The parser stores both values
        $limit = $statement->limit;

        // If there's a row_count, return it (it's the actual limit)
        if (property_exists($limit, 'rowCount')) {
            $rowCount = $limit->rowCount ?? null;
            if (null !== $rowCount && '' !== (string) $rowCount) {
                return (int) $rowCount;
            }
        }

        // Otherwise return offset (which in "LIMIT 100" is actually the row count)
        if (property_exists($limit, 'offset')) {
            $offset = $limit->offset ?? null;
            if (null !== $offset && '' !== (string) $offset) {
                return (int) $offset;
            }
        }

        return null;
    }

    /**
     * Checks if an expression contains a subquery.
     */
    private function expressionContainsSubquery(mixed $expr): bool
    {
        if (null === $expr) {
            return false;
        }

        $exprStr = (string) $expr;

        // Look for parentheses with SELECT
        return str_contains($exprStr, '(') && str_contains(strtoupper($exprStr), 'SELECT');
    }
}
