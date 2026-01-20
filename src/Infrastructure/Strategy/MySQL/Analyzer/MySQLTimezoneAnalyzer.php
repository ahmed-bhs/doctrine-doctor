<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\MySQL\Analyzer;

use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\TimezoneAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/**
 * MySQL-specific timezone analyzer.
 * Detects mismatches between MySQL, PHP, and system timezone configuration.
 */
final class MySQLTimezoneAnalyzer implements TimezoneAnalyzerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly DatabasePlatformDetector $databasePlatformDetector,
    ) {
    }

    public function analyze(): iterable
    {
        $mysqlTimezone  = $this->getMySQLTimezone();
        $phpTimezone    = $this->getPHPTimezone();
        $systemTimezone = $this->getSystemTimezone();

        // Issue 1: MySQL using SYSTEM timezone (ambiguous)
        // Skip if SYSTEM resolves to UTC and PHP is also UTC (common and acceptable)
        if ('SYSTEM' === $mysqlTimezone) {
            $isUTCEverywhere = ('UTC' === $systemTimezone && 'UTC' === $phpTimezone);

            if (!$isUTCEverywhere) {
                yield $this->createSystemTimezoneIssue($systemTimezone, $phpTimezone);
            }
        }

        // Issue 2: MySQL timezone != PHP timezone
        $effectiveMysqlTz = ('SYSTEM' === $mysqlTimezone) ? $systemTimezone : $mysqlTimezone;

        if ($this->timezonesAreDifferent($effectiveMysqlTz, $phpTimezone)) {
            yield $this->createTimezoneMismatchIssue($effectiveMysqlTz, $phpTimezone);
        }

        // Issue 3: Check if timezone tables are loaded
        if (!$this->areTimezoneTablesLoaded()) {
            yield $this->createMissingTimezoneTablesIssue();
        }
    }

    private function getMySQLTimezone(): string
    {
        $result = $this->connection->executeQuery(
            'SELECT @@global.time_zone as global_tz, @@session.time_zone as session_tz',
        );

        $row = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['session_tz'] ?? $row['global_tz'] ?? 'SYSTEM';
    }

    private function getSystemTimezone(): string
    {
        $result = $this->connection->executeQuery(
            'SELECT @@system_time_zone as system_tz',
        );

        $row = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['system_tz'] ?? 'UTC';
    }

    private function getPHPTimezone(): string
    {
        return date_default_timezone_get();
    }

    private function timezonesAreDifferent(string $tz1, string $tz2): bool
    {
        $normalize = function (string $timezone): string {
            // Pattern: Match numeric values
            if (1 === preg_match('/^[+-]\d{2}:\d{2}$/', $timezone)) {
                return $timezone;
            }

            try {
                $dateTimeZone = new DateTimeZone($timezone);

                return $dateTimeZone->getName();
            } catch (\Exception) {
                return $timezone;
            }
        };

        $normalized1 = $normalize($tz1);
        $normalized2 = $normalize($tz2);

        $utcEquivalents = ['UTC', '+00:00', 'GMT'];

        if (in_array($normalized1, $utcEquivalents, true) && in_array($normalized2, $utcEquivalents, true)) {
            return false;
        }

        return $normalized1 !== $normalized2;
    }

    private function areTimezoneTablesLoaded(): bool
    {
        try {
            $result = $this->connection->executeQuery(
                'SELECT COUNT(*) as count FROM mysql.time_zone_name',
            );

            $row = $this->databasePlatformDetector->fetchAssociative($result);

            return ($row['count'] ?? 0) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function createSystemTimezoneIssue(string $systemTimezone, string $phpTimezone): DatabaseConfigIssue
    {
        return new DatabaseConfigIssue([
            'title'       => 'MySQL using SYSTEM timezone (ambiguous configuration)',
            'description' => sprintf(
                'MySQL is configured to use the SYSTEM timezone, which resolves to "%s". ' .
                'This is ambiguous and can change if the server timezone changes. ' .
                'PHP is using "%s". ' .
                'Explicitly set MySQL timezone to ensure consistent datetime handling.',
                $systemTimezone,
                $phpTimezone,
            ),
            'severity'   => Severity::warning(),
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'MySQL time_zone',
                currentValue: 'SYSTEM',
                recommendedValue: $phpTimezone,
                description: 'Set explicit timezone to match PHP application timezone',
                fixCommand: $this->getTimezoneFixCommand($phpTimezone),
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function createTimezoneMismatchIssue(string $mysqlTz, string $phpTz): DatabaseConfigIssue
    {
        return new DatabaseConfigIssue([
            'title'       => 'Timezone mismatch between MySQL and PHP',
            'description' => sprintf(
                'MySQL timezone is "%s" but PHP timezone is "%s". ' .
                'This mismatch causes subtle bugs:' . "\n" .
                '- DateTime values saved from PHP are stored with wrong timezone' . "\n" .
                '- Queries with NOW(), CURDATE() return different times than PHP' . "\n" .
                '- Date comparisons between PHP and MySQL fail' . "\n" .
                '- Reports and analytics show incorrect timestamps',
                $mysqlTz,
                $phpTz,
            ),
            'severity'   => Severity::critical(),
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'Timezone configuration',
                currentValue: sprintf('MySQL: %s, PHP: %s', $mysqlTz, $phpTz),
                recommendedValue: sprintf('Both use: %s', $phpTz),
                description: 'Synchronize MySQL and PHP timezones to prevent datetime bugs',
                fixCommand: $this->getTimezoneFixCommand($phpTz),
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function createMissingTimezoneTablesIssue(): DatabaseConfigIssue
    {
        return new DatabaseConfigIssue([
            'title'       => 'MySQL timezone tables not loaded',
            'description' => 'MySQL timezone tables (mysql.time_zone_name) are empty. ' .
                'This prevents timezone conversions with CONVERT_TZ() and named timezones. ' .
                'You can only use offset-based timezones like "+00:00" which is inflexible.',
            'severity'   => Severity::warning(),
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'MySQL timezone tables',
                currentValue: 'Not loaded',
                recommendedValue: 'Loaded',
                description: 'Load timezone tables to enable timezone conversions',
                fixCommand: $this->getTimezoneTablesLoadCommand(),
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function getTimezoneFixCommand(string $timezone): string
    {
        return <<<SQL
            -- Option 1: Set in MySQL configuration file (my.cnf or my.ini) - RECOMMENDED
            [mysqld]
            default-time-zone = '{$timezone}'

            -- Option 2: Set dynamically (session only, temporary)
            SET time_zone = '{$timezone}';

            -- Option 3: Set globally (requires SUPER privilege, persists until restart)
            SET GLOBAL time_zone = '{$timezone}';

            -- Option 4: Set in Doctrine DBAL configuration (config/packages/doctrine.yaml)
            doctrine:
                dbal:
                    options:
                        1002: '{$timezone}'  # MYSQL_INIT_COMMAND equivalent
                    # OR use connection string
                    url: 'mysql://user:pass@host/dbname?serverVersion=8.0&charset=utf8mb4&default-time-zone={$timezone}'
            SQL;
    }

    private function getTimezoneTablesLoadCommand(): string
    {
        return <<<BASH
            # On Linux/Mac (run on host machine, not Docker container)
            mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root -p mysql

            # On Windows, download timezone SQL from:
            # https://dev.mysql.com/downloads/timezones.html
            # Then import with:
            # mysql -u root -p mysql < timezone_2024_*.sql

            # Verify it worked:
            # mysql -u root -p -e "SELECT COUNT(*) FROM mysql.time_zone_name;"
            # Should return > 0 (typically 500+)

            # After loading, restart MySQL or run:
            # FLUSH TABLES;
            BASH;
    }
}
