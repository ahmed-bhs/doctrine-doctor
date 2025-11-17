<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Interface\QueryNormalizerInterface;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\DeleteStatement;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Statements\UpdateStatement;

/**
 * Normalizes SQL queries for pattern matching.
 *
 * This class focuses solely on query normalization for N+1 detection,
 * following the Single Responsibility Principle.
 */
final class SqlQueryNormalizer implements QueryNormalizerInterface
{
    public function normalizeQuery(string $sql): string
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (null === $statement) {
            // Parser failed - fall back to regex normalization
            return $this->regexBasedNormalization($sql);
        }

        // Use parser to get structure, then normalize
        // Build normalized query by processing each part

        if ($statement instanceof SelectStatement) {
            return $this->normalizeSelectForNPlusOne($statement, $sql);
        }

        if ($statement instanceof UpdateStatement) {
            return $this->normalizeUpdateForNPlusOne($statement);
        }

        if ($statement instanceof DeleteStatement) {
            return $this->normalizeDeleteForNPlusOne($statement);
        }

        // Fallback for other statement types
        return $this->regexBasedNormalization($sql);
    }

    /**
     * Normalizes SELECT statement for N+1 detection.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function normalizeSelectForNPlusOne(SelectStatement $statement, string $originalSql): string
    {
        $parts = [];

        // SELECT clause
        $parts[] = 'SELECT';
        $statementOptions = $statement->options ?? null;
        if (null !== $statementOptions) {
            $options = $statementOptions->options ?? [];
            if ([] !== $options) {
                foreach ($options as $option) {
                    if (is_string($option)) {
                        $optionStr = $option;
                        if ('' !== $optionStr) {
                            $optStr = strtoupper(trim($optionStr));
                            if ('' !== $optStr && 'SQL_NO_CACHE' !== $optStr) {
                                $parts[] = $optStr;
                            }
                        }
                    }
                }
            }
        }

        // Simplified: just use * for all columns (we only care about structure)
        $parts[] = '*';

        // FROM clause
        $fromClauses = $statement->from ?? [];
        if ([] !== $fromClauses) {
            $parts[] = 'FROM';
            $fromTables = [];
            foreach ($fromClauses as $fromExpr) {
                $tableStr = (string) ($fromExpr->table ?? '');
                $table = strtoupper($tableStr);
                if ('' !== $table) {
                    $fromTables[] = $table;
                }
            }
            $parts[] = implode(', ', $fromTables);
        }

        // JOIN clauses
        $joins = $statement->join ?? [];
        if ([] !== $joins) {
            foreach ($joins as $join) {
                $joinTypeStr = (string) ($join->type ?? 'INNER');
                $joinType = strtoupper(trim($joinTypeStr));
                $parts[] = $joinType . ' JOIN';
                $tableStr = (string) ($join->expr->table ?? '');
                $table = strtoupper($tableStr);
                $parts[] = $table;
                // Normalize ON conditions
                $onConditions = $join->on ?? [];
                if ([] !== $onConditions) {
                    $parts[] = 'ON';
                    $conditions = [];
                    foreach ($onConditions as $condition) {
                        $condStr = (string) $condition;
                        // Replace values with ?
                        $condStr = preg_replace('/=\s*[\'"]?[^\'"\s,)]+[\'"]?/', '= ?', $condStr);
                        $conditions[] = strtoupper((string) $condStr);
                    }
                    $parts[] = implode(' AND ', $conditions);
                }
            }
        }

        // WHERE clause - most important for N+1 detection
        if (null !== $statement->where && [] !== $statement->where) {
            $parts[] = 'WHERE';
            $conditions = [];
            foreach ($statement->where as $condition) {
                $condStr = (string) $condition;
                // Replace literal values with ?
                $condStr = $this->replaceLiteralsInCondition($condStr);
                $conditions[] = strtoupper($condStr);
            }
            $parts[] = implode(' ', $conditions);
        }

        // GROUP BY (preserve structure)
        if (null !== $statement->group && [] !== $statement->group) {
            $parts[] = 'GROUP BY';
            $groupCols = [];
            foreach ($statement->group as $groupExpr) {
                $groupCols[] = strtoupper((string) $groupExpr->expr);
            }
            $parts[] = implode(', ', $groupCols);
        }

        // ORDER BY (preserve structure)
        if (null !== $statement->order && [] !== $statement->order) {
            $parts[] = 'ORDER BY';
            $orderCols = [];
            foreach ($statement->order as $orderExpr) {
                $orderCols[] = strtoupper((string) $orderExpr->expr);
            }
            $parts[] = implode(', ', $orderCols);
        }

        // LIMIT/OFFSET (normalize to just LIMIT ?)
        if (null !== $statement->limit) {
            $parts[] = 'LIMIT ?';
        }

        return implode(' ', $parts);
    }

    /**
     * Normalizes UPDATE statement for N+1 detection.
     */
    private function normalizeUpdateForNPlusOne(UpdateStatement $statement): string
    {
        $parts = ['UPDATE'];

        // Table
        $tableStr = (string) ($statement->tables[0]->table ?? 'UNKNOWN');
        $table = strtoupper($tableStr);
        $parts[] = $table;

        // SET clause - normalize all values to ?
        if (null !== $statement->set && [] !== $statement->set) {
            $parts[] = 'SET';
            $setParts = [];
            foreach ($statement->set as $setClause) {
                $setStr = (string) $setClause;
                $setStr = preg_replace('/=\s*[^\s,]+/', '= ?', $setStr);
                $setParts[] = strtoupper((string) $setStr);
            }
            $parts[] = implode(', ', $setParts);
        }

        // WHERE clause
        if (null !== $statement->where && [] !== $statement->where) {
            $parts[] = 'WHERE';
            $conditions = [];
            foreach ($statement->where as $condition) {
                $condStr = (string) $condition;
                $condStr = $this->replaceLiteralsInCondition($condStr);
                $conditions[] = strtoupper($condStr);
            }
            $parts[] = implode(' ', $conditions);
        }

        return implode(' ', $parts);
    }

    /**
     * Normalizes DELETE statement for N+1 detection.
     */
    private function normalizeDeleteForNPlusOne(DeleteStatement $statement): string
    {
        $parts = ['DELETE FROM'];

        // Table
        $tableStr = (string) ($statement->from[0]->table ?? 'UNKNOWN');
        $table = strtoupper($tableStr);
        $parts[] = $table;

        // WHERE clause
        if (null !== $statement->where && [] !== $statement->where) {
            $parts[] = 'WHERE';
            $conditions = [];
            foreach ($statement->where as $condition) {
                $condStr = (string) $condition;
                $condStr = $this->replaceLiteralsInCondition($condStr);
                $conditions[] = strtoupper($condStr);
            }
            $parts[] = implode(' ', $conditions);
        }

        return implode(' ', $parts);
    }

    /**
     * Replaces literal values in a condition string with placeholders.
     *
     * Handles:
     * - String literals: 'value' or "value" � ?
     * - Numeric literals: 123, 45.67 � ?
     * - IN clauses: IN (1, 2, 3) � IN (?)
     * - NULL values preserved
     */
    private function replaceLiteralsInCondition(string $condition): string
    {
        // Replace string literals (quoted)
        $normalized = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $condition);
        $normalized = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '?', (string) $normalized);

        // Replace IN clauses: IN (1, 2, 3) � IN (?)
        $normalized = preg_replace('/\bIN\s*\([^)]+\)/i', 'IN (?)', (string) $normalized);

        // Replace numeric literals (but not column names)
        $normalized = preg_replace('/\b(\d+(?:\.\d+)?)\b/', '?', (string) $normalized);

        // Normalize spacing around operators
        $normalized = preg_replace('/\s*=\s*/', ' = ', (string) $normalized);
        $normalized = preg_replace('/\s+/', ' ', (string) $normalized);

        return trim((string) $normalized);
    }

    /**
     * Fallback regex-based normalization when parser fails.
     */
    private function regexBasedNormalization(string $sql): string
    {
        // Normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', trim($sql));

        // Replace string literals
        $normalized = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', (string) $normalized);

        // Replace numeric literals
        $normalized = preg_replace('/\b(\d+)\b/', '?', (string) $normalized);

        // Normalize IN clauses
        $normalized = preg_replace('/IN\s*\([^)]+\)/i', 'IN (?)', (string) $normalized);

        // Normalize = placeholders
        $normalized = preg_replace('/=\s*\?/', '= ?', (string) $normalized);

        return strtoupper((string) $normalized);
    }
}
