<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

/**
 * Configuration object for MissingIndexAnalyzer.
 * Encapsulates all configuration parameters to reduce constructor complexity
 * and eliminate boolean flag anti-pattern.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
final class MissingIndexAnalyzerConfig
{
    public function __construct(
        /**
         * Slow query threshold in milliseconds
         * @readonly
         */
        public int $slowQueryThreshold = 50,
        /**
         * Minimum rows scanned before suggesting an index
         * @readonly
         */
        public int $minRowsScanned = 1000,
        /**
         * Maximum rows for acceptable filesort (1-to-many relations)
         * Queries with ORDER BY that return <= this many rows won't trigger suggestions
         * @readonly
         */
        public int $maxRowsForAcceptableFilesort = 10,
        /**
         * Enable or disable the analyzer
         * @readonly
         */
        public bool $enabled = true,
    ) {
    }

    /**
     * Create a disabled configuration (analyzer will skip analysis).
     */
    public static function disabled(): self
    {
        return new self(enabled: false);
    }

    /**
     * Create default configuration.
     */
    public static function default(): self
    {
        return new self();
    }

    /**
     * Create configuration with custom thresholds.
     */
    public static function withThresholds(int $slowQueryThreshold, int $minRowsScanned): self
    {
        return new self(
            slowQueryThreshold: $slowQueryThreshold,
            minRowsScanned: $minRowsScanned,
            enabled: true,
        );
    }
}
