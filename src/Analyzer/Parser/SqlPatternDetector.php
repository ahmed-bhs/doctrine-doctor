<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Interface\PatternDetectorInterface;
use PhpMyAdmin\SqlParser\Components\Condition;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\DeleteStatement;
use PhpMyAdmin\SqlParser\Statements\InsertStatement;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Statements\UpdateStatement;

/**
 * Detects common SQL query patterns.
 *
 * This class focuses solely on pattern detection (N+1, lazy loading, write operations),
 * following the Single Responsibility Principle.
 */
final readonly class SqlPatternDetector implements PatternDetectorInterface
{
    private SqlJoinExtractor $joinExtractor;

    public function __construct(?SqlJoinExtractor $joinExtractor = null)
    {
        $this->joinExtractor = $joinExtractor ?? new SqlJoinExtractor();
    }

    public function detectNPlusOnePattern(string $sql): ?array
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return null;
        }

        // Get main table from FROM clause
        $mainTable = $this->joinExtractor->extractMainTable($sql);
        if (null === $mainTable || null === $mainTable['table']) {
            return null;
        }

        // Check WHERE conditions for foreign key patterns (xxx_id = ?)
        if (null === $statement->where || [] === $statement->where) {
            return null;
        }

        foreach ($statement->where as $condition) {
            if (!$condition instanceof Condition) {
                continue;
            }

            $expr = trim((string) $condition->expr);

            // Look for patterns like: category_id = ? or t0.category_id = ?
            // Foreign keys typically end with _id
            if (1 === preg_match('/(?:\w+\.)?(\w+)_id\s*=/', $expr, $matches)) {
                $foreignKeyBase = $matches[1]; // e.g., "category" from "category_id"

                return [
                    'table' => $mainTable['table'],
                    'foreignKey' => $foreignKeyBase,
                ];
            }
        }

        return null;
    }

    public function detectNPlusOneFromJoin(string $sql): ?array
    {
        $joins = $this->joinExtractor->extractJoins($sql);

        foreach ($joins as $join) {
            if (null === $join['expr']->on || [] === $join['expr']->on) {
                continue;
            }

            foreach ($join['expr']->on as $condition) {
                if (!$condition instanceof Condition) {
                    continue;
                }

                $expr = trim((string) $condition->expr);

                // Look for: t.id = u.xxx_id or xxx_id = t.id
                if (1 === preg_match('/\w+\.id\s*=\s*\w+\.(\w+)_id/', $expr, $matches)) {
                    $foreignKeyBase = $matches[1];

                    return [
                        'table' => $join['table'],
                        'foreignKey' => $foreignKeyBase,
                    ];
                }

                if (1 === preg_match('/\w+\.(\w+)_id\s*=\s*\w+\.id/', $expr, $matches)) {
                    $foreignKeyBase = $matches[1];

                    return [
                        'table' => $join['table'],
                        'foreignKey' => $foreignKeyBase,
                    ];
                }
            }
        }

        return null;
    }

    public function detectLazyLoadingPattern(string $sql): ?string
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return null;
        }

        // Get main table
        $mainTable = $this->joinExtractor->extractMainTable($sql);
        if (null === $mainTable || null === $mainTable['table']) {
            return null;
        }

        // Check WHERE for "id = ?" pattern (not foreign_id)
        if (null === $statement->where || [] === $statement->where) {
            return null;
        }

        foreach ($statement->where as $condition) {
            if (!$condition instanceof Condition) {
                continue;
            }

            $expr = trim((string) $condition->expr);

            // Look for: id = ? or t0.id = ? or id = 123 or t0.id = 'value'
            // Avoid: user_id = ?, product_id = ? (foreign keys)
            // Use regex for precise pattern with negative lookbehind
            // Accepts: ?, numbers, strings in quotes
            if (1 === preg_match('/(?<![_\w])id\s*=\s*(?:\?|\d+|\'[^\']*\'|"[^"]*")/i', $expr)) {
                return $mainTable['table'];
            }
        }

        return null;
    }

    public function detectUpdateQuery(string $sql): ?string
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof UpdateStatement) {
            return null;
        }

        // Get table name from UPDATE statement
        if (null === $statement->tables || [] === $statement->tables) {
            return null;
        }

        $table = $statement->tables[0];

        return $table->table ?? null;
    }

    public function detectDeleteQuery(string $sql): ?string
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof DeleteStatement) {
            return null;
        }

        // Get table name from DELETE statement
        if (null === $statement->from || [] === $statement->from) {
            return null;
        }

        $table = $statement->from[0];

        return $table->table ?? null;
    }

    public function detectInsertQuery(string $sql): ?string
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof InsertStatement) {
            return null;
        }

        // Get table name from INSERT statement
        if (null === $statement->into) {
            return null;
        }

        return $statement->into->dest->table ?? null;
    }

    public function isSelectQuery(string $sql): bool
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        return $statement instanceof SelectStatement;
    }

    public function detectPartialCollectionLoad(string $sql): bool
    {
        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;

        if (!$statement instanceof SelectStatement) {
            return false;
        }

        // Check if query has LIMIT (partial collection access)
        $hasLimit = null !== $statement->limit;
        if (!$hasLimit) {
            return false;
        }

        // Check if query has foreign key pattern (collection load)
        $hasForeignKey = null !== $this->detectNPlusOnePattern($sql);

        return $hasForeignKey;
    }
}
