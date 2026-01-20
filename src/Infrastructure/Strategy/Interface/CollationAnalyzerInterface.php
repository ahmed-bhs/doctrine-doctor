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
 * Interface for platform-specific collation analysis.
 *
 * - MySQL/MariaDB: utf8mb4_general_ci vs utf8mb4_unicode_ci, mismatches
 * - PostgreSQL: "C" vs locale-aware collations, ICU vs libc
 * - SQLite: N/A (limited collation support, skip)
 */
interface CollationAnalyzerInterface
{
    /**
     * Analyze collation configuration for the platform.
     *
     * @return iterable<IssueInterface>
     */
    public function analyze(): iterable;
}
