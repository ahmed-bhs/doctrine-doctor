<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector\Helper;

/**
 * Configuration for DataCollector helpers.
 * Eliminates boolean flag anti-pattern.
 */
final class DataCollectorConfig
{
    public function __construct(
        /**
         * @readonly
         */
        public bool $debugMode = false,
    ) {
    }

    /**
     * Create configuration with debug mode enabled.
     */
    public static function withDebug(): self
    {
        return new self(debugMode: true);
    }

    /**
     * Create configuration with debug mode disabled.
     */
    public static function withoutDebug(): self
    {
        return new self(debugMode: false);
    }
}
