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
 * Interface for platform-specific timezone analysis.
 *
 * - MySQL/MariaDB: time_zone settings, PHP/MySQL mismatch
 * - PostgreSQL: timezone settings, TIMESTAMP vs TIMESTAMPTZ, PHP/PG mismatch
 * - SQLite: N/A (no timezone support, skip)
 */
interface TimezoneAnalyzerInterface
{
    /**
     * Analyze timezone configuration for the platform.
     *
     * @return iterable<IssueInterface>
     */
    public function analyze(): iterable;
}
