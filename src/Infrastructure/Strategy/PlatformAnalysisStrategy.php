<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Infrastructure\Strategy;

use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;

/**
 * Strategy interface for platform-specific database analysis.
 * Each platform (MySQL, PostgreSQL, SQLite) implements this interface
 * to provide platform-specific analysis logic for various database concerns.
 */
interface PlatformAnalysisStrategy
{
    /**
     * Analyze charset/encoding configuration.
     * MySQL/MariaDB: utf8 vs utf8mb4
     * PostgreSQL: server_encoding, client_encoding, SQL_ASCII detection
     * SQLite: N/A (skip)
     * @return iterable<IssueInterface>
     */
    public function analyzeCharset(): iterable;

    /**
     * Analyze collation configuration.
     * MySQL/MariaDB: utf8mb4_general_ci vs utf8mb4_unicode_ci, mismatches
     * PostgreSQL: "C" vs locale-aware collations, ICU vs libc
     * SQLite: N/A (limited collation support, skip)
     * @return iterable<IssueInterface>
     */
    public function analyzeCollation(): iterable;

    /**
     * Analyze timezone configuration.
     * MySQL/MariaDB: time_zone settings, PHP/MySQL mismatch
     * PostgreSQL: timezone settings, TIMESTAMP vs TIMESTAMPTZ, PHP/PG mismatch
     * SQLite: N/A (no timezone support, skip)
     * @return iterable<IssueInterface>
     */
    public function analyzeTimezone(): iterable;

    /**
     * Analyze connection pooling configuration.
     * MySQL/MariaDB: max_connections, wait_timeout, connection usage
     * PostgreSQL: max_connections, idle_in_transaction_session_timeout, pg_stat_activity
     * SQLite: N/A (embedded database, skip)
     * @return iterable<IssueInterface>
     */
    public function analyzeConnectionPooling(): iterable;

    /**
     * Analyze strict mode / data integrity settings.
     * MySQL/MariaDB: sql_mode (STRICT_TRANS_TABLES, etc.)
     * PostgreSQL: standard_conforming_strings, check_function_bodies
     * SQLite: foreign_keys pragma (CRITICAL!)
     * @return iterable<IssueInterface>
     */
    public function analyzeStrictMode(): iterable;

    /**
     * Analyze performance-related configuration.
     * MySQL/MariaDB: query_cache (deprecated), innodb_flush_log, binary logs, buffer_pool_size
     * PostgreSQL: shared_buffers, work_mem, synchronous_commit
     * SQLite: N/A (embedded database, skip)
     * @return iterable<IssueInterface>
     */
    public function analyzePerformanceConfig(): iterable;

    /**
     * Check if this strategy supports a specific analysis feature.
     * @param string $feature Feature name (charset, collation, timezone, pooling, strict_mode, performance)
     * @return bool True if feature is supported on this platform
     */
    public function supportsFeature(string $feature): bool;

    /**
     * Get the platform name this strategy handles.
     * @return string Platform name (mysql, mariadb, postgresql, sqlite)
     */
    public function getPlatformName(): string;
}
