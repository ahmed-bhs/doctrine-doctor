<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser;

use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;

/**
 * Extracts structure information from SQL queries using a proper SQL parser.
 *
 * This replaces complex regex patterns with a robust SQL parser that:
 * - Properly handles SQL syntax (subqueries, nested expressions, etc.)
 * - Automatically normalizes JOIN types (LEFT OUTER → LEFT)
 * - Correctly identifies table names and aliases
 * - Avoids false positives (e.g., capturing 'ON' as an alias)
 */
class SqlStructureExtractor
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
    public function extractJoins(string $sql): array
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return [];
        }

        if (null === $statement->join || [] === $statement->join) {
            return [];
        }

        $joins = [];

        foreach ($statement->join as $join) {
            $type = $join->type ?? 'INNER';

            // Normalize JOIN type (LEFT OUTER → LEFT, etc.)
            $type = $this->normalizeJoinType($type);

            $table = $join->expr->table ?? null;
            $alias = $join->expr->alias ?? null;

            // Skip invalid joins
            if (null === $table) {
                continue;
            }

            $joins[] = [
                'type' => $type,
                'table' => $table,
                'alias' => $alias,
                'expr' => $join,  // Keep full expression for advanced use cases
            ];
        }

        return $joins;
    }

    /**
     * Extracts the main table from the FROM clause.
     *
     * @return array{table: string, alias: ?string}|null
     */
    public function extractMainTable(string $sql): ?array
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return null;
        }

        if (null === $statement->from || [] === $statement->from) {
            return null;
        }

        $from = $statement->from[0];

        return [
            'table' => $from->table ?? null,
            'alias' => $from->alias ?? null,
        ];
    }

    /**
     * Extracts all tables (FROM + JOINs).
     *
     * @return array<int, array{table: string, alias: ?string, source: string}>
     *
     * Source can be: 'from' or 'join'
     */
    public function extractAllTables(string $sql): array
    {
        $tables = [];

        // Main table from FROM clause
        $mainTable = $this->extractMainTable($sql);
        if (null !== $mainTable && null !== $mainTable['table']) {
            $tables[] = [
                'table' => $mainTable['table'],
                'alias' => $mainTable['alias'],
                'source' => 'from',
            ];
        }

        // Tables from JOINs
        $joins = $this->extractJoins($sql);
        foreach ($joins as $join) {
            $tables[] = [
                'table' => $join['table'],
                'alias' => $join['alias'],
                'source' => 'join',
            ];
        }

        return $tables;
    }

    /**
     * Checks if the SQL query contains any JOIN.
     */
    public function hasJoin(string $sql): bool
    {
        return [] !== $this->extractJoins($sql);
    }

    /**
     * Counts the number of JOINs in the query.
     */
    public function countJoins(string $sql): int
    {
        return count($this->extractJoins($sql));
    }

    /**
     * Normalizes JOIN type to standard format.
     *
     * LEFT OUTER → LEFT
     * RIGHT OUTER → RIGHT
     * JOIN → INNER
     * Empty → INNER
     */
    private function normalizeJoinType(string $type): string
    {
        $type = strtoupper(trim($type));

        return match ($type) {
            'LEFT OUTER' => 'LEFT',
            'RIGHT OUTER' => 'RIGHT',
            'JOIN', '' => 'INNER',  // JOIN without type = INNER JOIN
            default => $type,
        };
    }
}
