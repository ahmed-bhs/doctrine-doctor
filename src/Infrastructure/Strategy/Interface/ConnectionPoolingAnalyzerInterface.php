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
 * Interface for platform-specific connection pooling analysis.
 *
 * - MySQL/MariaDB: max_connections, wait_timeout, connection usage
 * - PostgreSQL: max_connections, idle_in_transaction_session_timeout, pg_stat_activity
 * - SQLite: N/A (embedded database, skip)
 */
interface ConnectionPoolingAnalyzerInterface
{
    /**
     * Analyze connection pooling configuration for the platform.
     *
     * @return iterable<IssueInterface>
     */
    public function analyze(): iterable;
}
