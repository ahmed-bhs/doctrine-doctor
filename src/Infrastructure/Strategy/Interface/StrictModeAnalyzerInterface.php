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
 * Interface for platform-specific strict mode / data integrity analysis.
 *
 * - MySQL/MariaDB: sql_mode (STRICT_TRANS_TABLES, etc.)
 * - PostgreSQL: standard_conforming_strings, check_function_bodies
 * - SQLite: foreign_keys pragma (CRITICAL!)
 */
interface StrictModeAnalyzerInterface
{
    /**
     * Analyze strict mode / data integrity settings for the platform.
     *
     * @return iterable<IssueInterface>
     */
    public function analyze(): iterable;
}
