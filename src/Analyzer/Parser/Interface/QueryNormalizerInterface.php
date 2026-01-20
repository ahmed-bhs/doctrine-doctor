<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser\Interface;

/**
 * Interface for normalizing SQL queries for pattern matching.
 *
 * Used primarily for N+1 detection by converting queries with different
 * parameter values into the same normalized pattern.
 */
interface QueryNormalizerInterface
{
    /**
     * Normalizes SQL query for pattern matching across all analyzers.
     *
     * This is the universal normalization method used by:
     * - NPlusOneAnalyzer: Detect repeated query patterns
     * - BulkOperationAnalyzer: Group similar bulk operations
     * - MissingIndexAnalyzer: Identify repetitive queries
     *
     * Replaces all literal values with placeholders to group similar queries:
     * - SELECT * FROM users WHERE id = 1 → SELECT * FROM USERS WHERE ID = ?
     * - SELECT * FROM users WHERE id = 2 → SELECT * FROM USERS WHERE ID = ?
     * - SELECT * FROM users WHERE id = 3 → SELECT * FROM USERS WHERE ID = ?
     * - UPDATE users SET name = 'John' WHERE id = 1 → UPDATE USERS SET NAME = ? WHERE ID = ?
     * - DELETE FROM users WHERE id = 1 → DELETE FROM USERS WHERE ID = ?
     *
     * This allows detecting patterns where the same query structure
     * is executed multiple times with different parameter values.
     *
     * Normalization steps:
     * 1. Parse SQL using phpmyadmin/sql-parser
     * 2. Replace all literal values (strings, numbers) with ?
     * 3. Normalize IN clauses: IN (1,2,3) → IN (?)
     * 4. Uppercase for case-insensitive comparison
     * 5. Normalize whitespace
     *
     * @return string Normalized query pattern
     */
    public function normalizeQuery(string $sql): string;
}
