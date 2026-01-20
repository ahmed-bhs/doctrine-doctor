<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface;

use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;

/**
 * Interface for platform-specific performance configuration analysis.
 *
 * - MySQL/MariaDB: query_cache (deprecated), innodb_flush_log, binary logs, buffer_pool_size
 * - PostgreSQL: shared_buffers, work_mem, synchronous_commit
 * - SQLite: N/A (embedded database, skip)
 */
interface PerformanceConfigAnalyzerInterface
{
    /**
     * Analyze performance-related configuration for the platform.
     *
     * @return iterable<IssueInterface>
     */
    public function analyze(): iterable;
}
