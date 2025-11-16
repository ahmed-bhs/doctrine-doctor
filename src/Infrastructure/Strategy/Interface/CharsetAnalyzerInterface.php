<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface;

use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;

/**
 * Interface for platform-specific charset/encoding analysis.
 *
 * - MySQL/MariaDB: utf8 vs utf8mb4
 * - PostgreSQL: server_encoding, client_encoding, SQL_ASCII detection
 * - SQLite: N/A (skip)
 */
interface CharsetAnalyzerInterface
{
    /**
     * Analyze charset/encoding configuration for the platform.
     *
     * @return iterable<IssueInterface>
     */
    public function analyze(): iterable;
}
