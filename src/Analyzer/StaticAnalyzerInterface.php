<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer;

/**
 * Marks analyzers that do not depend on SQL captured from the current request
 * and therefore belong in the CI analysis command. Database-backed checks use
 * the more specific DatabaseAuditAnalyzerInterface marker.
 */
interface StaticAnalyzerInterface extends AnalyzerInterface
{
}
