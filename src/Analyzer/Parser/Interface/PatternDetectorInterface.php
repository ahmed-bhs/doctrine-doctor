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
 * Interface for detecting common SQL query patterns.
 *
 * Detects patterns like N+1 queries, lazy loading, and write operations.
 */
interface PatternDetectorInterface
{
    /**
     * Detects potential N+1 query pattern by analyzing foreign key columns in WHERE.
     * Returns information about the potential N+1 pattern or null if not detected.
     *
     * Pattern detected: SELECT ... FROM table WHERE xxx_id = ?
     * This suggests potential N+1 when loading related entities one by one.
     *
     * @return array{table: string, foreignKey: string}|null
     */
    public function detectNPlusOnePattern(string $sql): ?array;

    /**
     * Detects N+1 pattern from JOIN conditions.
     * Pattern: JOIN table ON t.id = u.xxx_id
     *
     * @return array{table: string, foreignKey: string}|null
     */
    public function detectNPlusOneFromJoin(string $sql): ?array;

    /**
     * Detects lazy loading pattern: SELECT ... FROM table WHERE id = ?
     * This pattern suggests single entity load by ID (lazy loading).
     * Uses negative lookbehind to avoid matching foreign keys like user_id, product_id.
     *
     * @return string|null Table name if lazy loading pattern detected, null otherwise
     */
    public function detectLazyLoadingPattern(string $sql): ?string;

    /**
     * Detects UPDATE query and extracts table name.
     * Pattern: UPDATE table SET ... WHERE ...
     *
     * @return string|null Table name if UPDATE detected, null otherwise
     */
    public function detectUpdateQuery(string $sql): ?string;

    /**
     * Detects DELETE query and extracts table name.
     * Pattern: DELETE FROM table WHERE ...
     *
     * @return string|null Table name if DELETE detected, null otherwise
     */
    public function detectDeleteQuery(string $sql): ?string;

    /**
     * Detects INSERT query and extracts table name.
     * Pattern: INSERT INTO table (...) VALUES (...)
     *
     * @return string|null Table name if INSERT detected, null otherwise
     */
    public function detectInsertQuery(string $sql): ?string;

    /**
     * Checks if a SQL query is a SELECT statement.
     */
    public function isSelectQuery(string $sql): bool;

    /**
     * Detects partial collection load pattern (collection N+1 with LIMIT).
     * Pattern: SELECT ... FROM items WHERE parent_id = ? LIMIT X
     * This suggests extra lazy collection access or pagination within collection.
     *
     * @return bool True if partial collection load detected, false otherwise
     */
    public function detectPartialCollectionLoad(string $sql): bool;
}
