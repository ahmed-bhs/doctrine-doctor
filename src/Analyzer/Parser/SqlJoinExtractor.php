<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Interface\JoinExtractorInterface;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;

/**
 * Extracts JOIN information from SQL queries.
 *
 * This class focuses solely on JOIN extraction and analysis,
 * following the Single Responsibility Principle.
 */
final class SqlJoinExtractor implements JoinExtractorInterface
{
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

            // Normalize JOIN type (LEFT OUTER � LEFT, etc.)
            $type = $this->normalizeJoinType($type);

            // Table name can be in either ->table or ->expr depending on parser behavior
            $table = $join->expr->table ?? $join->expr->expr ?? null;
            $alias = $join->expr->alias ?? null;

            // Skip invalid joins (no table name found)
            if (null === $table || '' === $table) {
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
        $table = $from->table ?? null;

        if (null === $table) {
            return null;
        }

        return [
            'table' => $table,
            'alias' => $from->alias ?? null,
        ];
    }

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

    public function getAllTableNames(string $sql): array
    {
        $allTables = $this->extractAllTables($sql);
        $tableNames = [];

        foreach ($allTables as $tableInfo) {
            if (null !== $tableInfo['table']) {
                $tableNames[] = strtolower($tableInfo['table']);
            }
        }

        return array_values(array_unique($tableNames));
    }

    public function hasTable(string $sql, string $tableName): bool
    {
        $tableNames = $this->getAllTableNames($sql);
        $searchTable = strtolower($tableName);

        return in_array($searchTable, $tableNames, true);
    }

    public function hasJoin(string $sql): bool
    {
        return [] !== $this->extractJoins($sql);
    }

    public function hasJoins(string $sql): bool
    {
        return [] !== $this->extractJoins($sql);
    }

    public function countJoins(string $sql): int
    {
        return count($this->extractJoins($sql));
    }

    public function extractJoinOnClause(string $sql, string $joinExpression): ?string
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return null;
        }

        if (null === $statement->join || [] === $statement->join) {
            return null;
        }

        // Find matching JOIN by comparing table/alias
        foreach ($statement->join as $join) {
            $joinTable = $join->expr->table ?? null;
            $joinAlias = $join->expr->alias ?? null;

            // Build this JOIN's expression to match against provided joinExpression
            if (null !== $joinAlias) {
            }

            // Check if this matches the requested JOIN
            if (false !== stripos($joinExpression, (string) $joinTable)) {
                // Extract ON clause
                if (null !== $join->on && [] !== $join->on) {
                    $onConditions = [];
                    foreach ($join->on as $condition) {
                        $onConditions[] = trim((string) $condition);
                    }
                    return implode(' AND ', $onConditions);
                }
            }
        }

        return null;
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function extractTableNameWithAlias(string $sql, string $targetAlias): ?array
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return null;
        }

        // Check FROM clause
        $fromClause = $statement->from ?? [];
        if ([] !== $fromClause) {
            foreach ($fromClause as $fromTable) {
                $alias = $fromTable->alias ?? $fromTable->table;

                if ($alias === $targetAlias && null !== $fromTable->table) {
                    $displayAlias = is_string($fromTable->alias) ? $fromTable->alias : null;
                    return [
                        'realName' => $fromTable->table,
                        'display'  => $fromTable->table . (null !== $displayAlias ? ' ' . $displayAlias : ''),
                        'alias'    => $alias,
                    ];
                }
            }
        }

        // Check JOIN clauses
        if (null !== $statement->join && [] !== $statement->join) {
            foreach ($statement->join as $join) {
                if (null === $join->expr) {
                    continue;
                }

                $joinTable = null;
                $joinAlias = null;

                // Extract table expression from JOIN
                if (is_array($join->expr)) {
                    foreach ($join->expr as $expr) {
                        if ($expr instanceof \PhpMyAdmin\SqlParser\Components\Expression) {
                            $joinTable = $expr->table ?? null;
                            $joinAlias = $expr->alias ?? $expr->table;
                        }
                    }
                }

                if ($joinAlias === $targetAlias && null !== $joinTable && is_string($joinTable)) {
                    $displayAlias = is_string($joinAlias) ? $joinAlias : $joinTable;
                    return [
                        'realName' => $joinTable,
                        'display'  => $joinTable . ($joinAlias !== $joinTable && is_string($joinAlias) ? ' ' . $joinAlias : ''),
                        'alias'    => $displayAlias,
                    ];
                }
            }
        }

        // Fallback: target alias might be the table name itself
        return [
            'realName' => $targetAlias,
            'display'  => $targetAlias,
            'alias'    => $targetAlias,
        ];
    }

    /**
     * Normalizes JOIN type to standard format.
     *
     * LEFT OUTER � LEFT
     * RIGHT OUTER � RIGHT
     * JOIN � INNER
     * Empty � INNER
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
