<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Factory;

use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\MySQL\MySQLAnalysisStrategy;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\PlatformAnalysisStrategy;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\PostgreSQL\PostgreSQLAnalysisStrategy;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Factory for creating platform-specific analysis strategies.
 * Creates the appropriate strategy instance based on the detected database platform.
 */
class PlatformAnalysisStrategyFactory
{
    public function __construct(
        /**
         * @readonly
         */
        private Connection $connection,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
        /**
         * @readonly
         */
        private DatabasePlatformDetector $databasePlatformDetector,
    ) {
    }

    /**
     * Create a platform-specific analysis strategy.
     * @throws \RuntimeException If platform is not supported
     */
    public function createStrategy(): PlatformAnalysisStrategy
    {
        $platformName = $this->databasePlatformDetector->getPlatformName();

        return match ($platformName) {
            'mysql', 'mariadb' => new MySQLAnalysisStrategy(
                $this->connection,
                $this->suggestionFactory,
                $this->databasePlatformDetector,
            ),
            'postgresql' => new PostgreSQLAnalysisStrategy(
                $this->connection,
                $this->suggestionFactory,
                $this->databasePlatformDetector,
            ),
            // SQLite support can be added later if needed
            // 'sqlite' => new SQLiteAnalysisStrategy(...),
            default => throw new RuntimeException(sprintf('Unsupported database platform for analysis: %s', $platformName)),
        };
    }

    /**
     * Check if a platform is supported for analysis.
     * @param string $platformName Platform name (mysql, postgresql, sqlite, etc.)
     * @return bool True if platform is supported
     */
    public function isPlatformSupported(string $platformName): bool
    {
        return in_array($platformName, ['mysql', 'mariadb', 'postgresql'], true);
    }

    /**
     * Get the current database platform name.
     * @return string Platform name
     */
    public function getPlatformName(): string
    {
        return $this->databasePlatformDetector->getPlatformName();
    }
}
