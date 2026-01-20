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
 * Interface for analyzing SQL performance patterns.
 *
 * Detects patterns that can impact query performance.
 */
interface PerformanceAnalyzerInterface
{
    /**
     * Checks if the SQL query has an ORDER BY clause.
     */
    public function hasOrderBy(string $sql): bool;

    /**
     * Checks if the SQL query has a LIMIT clause.
     */
    public function hasLimit(string $sql): bool;

    /**
     * Checks if the SQL query has an OFFSET clause.
     */
    public function hasOffset(string $sql): bool;

    /**
     * Detects if query contains subqueries (nested SELECT statements).
     *
     * Subqueries can appear in:
     * - SELECT clause: SELECT id, (SELECT COUNT(*) FROM orders WHERE user_id = u.id)
     * - WHERE clause: WHERE id IN (SELECT user_id FROM orders)
     * - FROM clause: FROM (SELECT * FROM users WHERE active = 1) AS active_users
     *
     * Optimization hint: Subqueries are often slower than JOINs
     *
     * @return bool True if subquery detected
     */
    public function hasSubquery(string $sql): bool;

    /**
     * Detects if query has GROUP BY clause.
     *
     * GROUP BY requires grouping which can be expensive without proper indexes.
     *
     * @return bool True if GROUP BY clause present
     */
    public function hasGroupBy(string $sql): bool;

    /**
     * Detects LIKE with leading wildcard (e.g., LIKE '%value').
     *
     * Leading wildcards prevent index usage, making queries very slow.
     *
     * Pattern: LIKE '%...' or LIKE "%..."
     *
     * @return bool True if leading wildcard LIKE detected
     */
    public function hasLeadingWildcardLike(string $sql): bool;

    /**
     * Detects if query uses SELECT DISTINCT or aggregation functions with DISTINCT.
     *
     * DISTINCT requires removing duplicates which can be expensive.
     * Often indicates missing proper JOINs or normalization.
     *
     * Detects:
     * - SELECT DISTINCT ...
     * - COUNT(DISTINCT ...), SUM(DISTINCT ...), AVG(DISTINCT ...), etc.
     *
     * @return bool True if DISTINCT detected
     */
    public function hasDistinct(string $sql): bool;

    /**
     * Gets the LIMIT value from SQL query.
     * Supports various formats:
     * - LIMIT 100
     * - LIMIT 10, 100 (offset, limit)
     * - LIMIT 100 OFFSET 10
     *
     * @return int|null LIMIT value if found, null otherwise
     */
    public function getLimitValue(string $sql): ?int;
}
