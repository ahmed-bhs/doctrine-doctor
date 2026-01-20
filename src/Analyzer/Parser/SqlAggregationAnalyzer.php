<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Interface\AggregationAnalyzerInterface;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;

/**
 * Analyzes SQL aggregation functions and related clauses.
 *
 * This class focuses solely on aggregation analysis (COUNT, SUM, GROUP BY, etc.),
 * following the Single Responsibility Principle.
 */
final class SqlAggregationAnalyzer implements AggregationAnalyzerInterface
{
    public function extractAggregationFunctions(string $sql): array
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return [];
        }

        $aggregations = [];
        $selectExpressions = $statement->expr ?? [];

        foreach ($selectExpressions as $expr) {
            if ($expr instanceof Expression) {
                // Look for function calls in the expression
                if (1 === preg_match('/\b(COUNT|SUM|AVG|MIN|MAX)\s*\(/i', (string) $expr, $match)) {
                    $aggregations[] = strtoupper($match[1]);
                }
            }
        }

        return array_unique($aggregations);
    }

    public function extractGroupByColumns(string $sql): array
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return [];
        }

        if (null === $statement->group || [] === $statement->group) {
            return [];
        }

        $columns = [];
        foreach ($statement->group as $groupExpr) {
            $exprStr = (string) $groupExpr->expr;
            $columnName = trim($exprStr);

            if ('' !== $columnName) {
                $columns[] = $columnName;
            }
        }

        return array_unique($columns);
    }

    public function extractOrderBy(string $sql): ?string
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return null;
        }

        if (null === $statement->order || [] === $statement->order) {
            return null;
        }

        // Build ORDER BY clause string from parsed components
        $orderParts = [];
        foreach ($statement->order as $orderItem) {
            $expr = trim((string) $orderItem->expr);

            // OrderSortKeyword is an enum (PHP 8.1+), get its name
            $type = '';
            if (null !== $orderItem->type && $orderItem->type instanceof \UnitEnum) {
                $type = strtoupper($orderItem->type->name); // Asc or Desc ’ ASC or DESC
            }

            if ('' !== $expr) {
                $orderParts[] = '' !== $type ? "{$expr} {$type}" : $expr;
            }
        }

        return [] !== $orderParts ? implode(', ', $orderParts) : null;
    }

    public function extractOrderByColumnNames(string $sql): array
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return [];
        }

        if (null === $statement->order || [] === $statement->order) {
            return [];
        }

        $columns = [];
        foreach ($statement->order as $orderItem) {
            $expr = trim((string) $orderItem->expr);

            // Extract column name (simple case: just column name or table.column)
            if (1 === preg_match('/(?:\w+\.)?(\w+)/', $expr, $match)) {
                $columns[] = strtolower($match[1]);
            }
        }

        return array_values(array_unique($columns));
    }

    public function extractSelectClause(string $sql): ?string
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return null;
        }

        if (null === $statement->expr || [] === $statement->expr) {
            return null;
        }

        // Concatenate all select expressions
        $selectParts = [];
        foreach ($statement->expr as $expr) {
            $selectParts[] = (string) $expr;
        }

        return implode(', ', $selectParts);
    }

    public function extractTableAliasesFromSelect(string $sql): array
    {
        $selectClause = $this->extractSelectClause($sql);

        if (null === $selectClause) {
            return [];
        }

        // Pattern: t0_.column, t1_.column ’ extract t0, t1
        $matchResult = preg_match_all('/(\w+)_\.\w+/', $selectClause, $matches);
        if (false === $matchResult || 0 === $matchResult) {
            return [];
        }

        $aliases = $matches[1];
        if ([] === $aliases) {
        }

        return array_unique($matches[1]);
    }
}
