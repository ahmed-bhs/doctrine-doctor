<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\MySQL;

use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\CharsetAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\CollationAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\ConnectionPoolingAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\PerformanceConfigAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\StrictModeAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\TimezoneAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\MySQL\Analyzer\MySQLCharsetAnalyzer;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\MySQL\Analyzer\MySQLCollationAnalyzer;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\MySQL\Analyzer\MySQLConnectionPoolingAnalyzer;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\MySQL\Analyzer\MySQLPerformanceConfigAnalyzer;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\MySQL\Analyzer\MySQLStrictModeAnalyzer;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\MySQL\Analyzer\MySQLTimezoneAnalyzer;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\PlatformAnalysisStrategy;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use Doctrine\DBAL\Connection;

/**
 * Facade for MySQL and MariaDB platform analysis.
 * Delegates to specialized analyzers following Single Responsibility Principle.
 *
 * This facade maintains backward compatibility while allowing dependency injection
 * of specialized analyzers for testing and extensibility.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
class MySQLAnalysisStrategy implements PlatformAnalysisStrategy
{
    private readonly CharsetAnalyzerInterface $charsetAnalyzer;

    private readonly CollationAnalyzerInterface $collationAnalyzer;

    private readonly TimezoneAnalyzerInterface $timezoneAnalyzer;

    private readonly ConnectionPoolingAnalyzerInterface $connectionPoolingAnalyzer;

    private readonly StrictModeAnalyzerInterface $strictModeAnalyzer;

    private readonly PerformanceConfigAnalyzerInterface $performanceConfigAnalyzer;

    public function __construct(
        private readonly Connection $connection,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly DatabasePlatformDetector $databasePlatformDetector,
        ?CharsetAnalyzerInterface $charsetAnalyzer = null,
        ?CollationAnalyzerInterface $collationAnalyzer = null,
        ?TimezoneAnalyzerInterface $timezoneAnalyzer = null,
        ?ConnectionPoolingAnalyzerInterface $connectionPoolingAnalyzer = null,
        ?StrictModeAnalyzerInterface $strictModeAnalyzer = null,
        ?PerformanceConfigAnalyzerInterface $performanceConfigAnalyzer = null,
    ) {
        $this->charsetAnalyzer = $charsetAnalyzer ?? new MySQLCharsetAnalyzer(
            $this->connection,
            $this->suggestionFactory,
            $this->databasePlatformDetector,
        );

        $this->collationAnalyzer = $collationAnalyzer ?? new MySQLCollationAnalyzer(
            $this->connection,
            $this->suggestionFactory,
            $this->databasePlatformDetector,
        );

        $this->timezoneAnalyzer = $timezoneAnalyzer ?? new MySQLTimezoneAnalyzer(
            $this->connection,
            $this->suggestionFactory,
            $this->databasePlatformDetector,
        );

        $this->connectionPoolingAnalyzer = $connectionPoolingAnalyzer ?? new MySQLConnectionPoolingAnalyzer(
            $this->connection,
            $this->suggestionFactory,
            $this->databasePlatformDetector,
        );

        $this->strictModeAnalyzer = $strictModeAnalyzer ?? new MySQLStrictModeAnalyzer(
            $this->connection,
            $this->suggestionFactory,
            $this->databasePlatformDetector,
        );

        $this->performanceConfigAnalyzer = $performanceConfigAnalyzer ?? new MySQLPerformanceConfigAnalyzer(
            $this->connection,
            $this->suggestionFactory,
            $this->databasePlatformDetector,
        );
    }

    /**
     * Analyze charset configuration (utf8 vs utf8mb4).
     */
    public function analyzeCharset(): iterable
    {
        return $this->charsetAnalyzer->analyze();
    }

    /**
     * Analyze collation configuration and mismatches.
     */
    public function analyzeCollation(): iterable
    {
        return $this->collationAnalyzer->analyze();
    }

    /**
     * Analyze timezone configuration and PHP/MySQL mismatches.
     */
    public function analyzeTimezone(): iterable
    {
        return $this->timezoneAnalyzer->analyze();
    }

    /**
     * Analyze connection pooling settings.
     */
    public function analyzeConnectionPooling(): iterable
    {
        return $this->connectionPoolingAnalyzer->analyze();
    }

    /**
     * Analyze strict mode (sql_mode) settings.
     */
    public function analyzeStrictMode(): iterable
    {
        return $this->strictModeAnalyzer->analyze();
    }

    /**
     * Analyze performance configuration (query cache, InnoDB settings, binary logs, buffer pool).
     */
    public function analyzePerformanceConfig(): iterable
    {
        return $this->performanceConfigAnalyzer->analyze();
    }

    public function supportsFeature(string $feature): bool
    {
        return match ($feature) {
            'charset'     => true,
            'collation'   => true,
            'timezone'    => true,
            'pooling'     => true,
            'strict_mode' => true,
            'performance' => true,
            default       => false,
        };
    }

    public function getPlatformName(): string
    {
        return $this->databasePlatformDetector->isMariaDB() ? 'mariadb' : 'mysql';
    }
}
