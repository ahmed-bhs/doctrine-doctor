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
 * Marks static analyzers that inspect the configured database and require a
 * live database connection to run.
 */
interface DatabaseAuditAnalyzerInterface extends MetadataAnalyzerInterface
{
}
