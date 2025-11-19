<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\PostgreSQL;

use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\CharsetAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\CollationAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\ConnectionPoolingAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\PerformanceConfigAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\StrictModeAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\TimezoneAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\PlatformAnalysisStrategy;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\PostgreSQL\Analyzer\PostgreSQLCharsetAnalyzer;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\PostgreSQL\Analyzer\PostgreSQLCollationAnalyzer;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\PostgreSQL\Analyzer\PostgreSQLConnectionPoolingAnalyzer;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\PostgreSQL\Analyzer\PostgreSQLPerformanceConfigAnalyzer;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\PostgreSQL\Analyzer\PostgreSQLStrictModeAnalyzer;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\PostgreSQL\Analyzer\PostgreSQLTimezoneAnalyzer;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use Doctrine\DBAL\Connection;

/**
 * Facade for PostgreSQL platform analysis.
 * Delegates to specialized analyzers following Single Responsibility Principle.
 *
 * This facade maintains backward compatibility while allowing dependency injection
 * of specialized analyzers for testing and extensibility.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
class PostgreSQLAnalysisStrategy implements PlatformAnalysisStrategy
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
        $this->charsetAnalyzer = $charsetAnalyzer ?? new PostgreSQLCharsetAnalyzer(
            $this->connection,
            $this->suggestionFactory,
            $this->databasePlatformDetector,
        );

        $this->collationAnalyzer = $collationAnalyzer ?? new PostgreSQLCollationAnalyzer(
            $this->connection,
            $this->suggestionFactory,
            $this->databasePlatformDetector,
        );

        $this->timezoneAnalyzer = $timezoneAnalyzer ?? new PostgreSQLTimezoneAnalyzer(
            $this->connection,
            $this->suggestionFactory,
            $this->databasePlatformDetector,
        );

        $this->connectionPoolingAnalyzer = $connectionPoolingAnalyzer ?? new PostgreSQLConnectionPoolingAnalyzer(
            $this->connection,
            $this->suggestionFactory,
            $this->databasePlatformDetector,
        );

        $this->strictModeAnalyzer = $strictModeAnalyzer ?? new PostgreSQLStrictModeAnalyzer(
            $this->connection,
            $this->suggestionFactory,
            $this->databasePlatformDetector,
        );

        $this->performanceConfigAnalyzer = $performanceConfigAnalyzer ?? new PostgreSQLPerformanceConfigAnalyzer(
            $this->connection,
            $this->suggestionFactory,
            $this->databasePlatformDetector,
        );
    }

    /**
     * Analyze encoding configuration (UTF8, SQL_ASCII, client_encoding).
     */
    public function analyzeCharset(): iterable
    {
        return $this->charsetAnalyzer->analyze();
    }

    /**
     * Analyze collation settings ("C" vs locale-aware, libc vs ICU).
     */
    public function analyzeCollation(): iterable
    {
        return $this->collationAnalyzer->analyze();
    }

    /**
     * Analyze timezone configuration and TIMESTAMP vs TIMESTAMPTZ detection.
     */
    public function analyzeTimezone(): iterable
    {
        return $this->timezoneAnalyzer->analyze();
    }

    /**
     * Analyze connection pooling (max_connections, idle_in_transaction_session_timeout).
     */
    public function analyzeConnectionPooling(): iterable
    {
        return $this->connectionPoolingAnalyzer->analyze();
    }

    /**
     * Analyze strict mode settings (standard_conforming_strings, etc.).
     */
    public function analyzeStrictMode(): iterable
    {
        return $this->strictModeAnalyzer->analyze();
    }

    /**
     * Analyze performance configuration (shared_buffers, work_mem, etc.).
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
        return 'postgresql';
    }
}
