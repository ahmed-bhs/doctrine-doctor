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
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\PlatformAnalysisStrategy;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/**
 * Analysis strategy for MySQL and MariaDB platforms.
 * Implements platform-specific analysis logic for:
 * - Charset configuration (utf8 vs utf8mb4)
 * - Collation settings
 * - Timezone configuration
 * - Connection pooling (max_connections, wait_timeout)
 * - Strict mode (sql_mode settings)
 */
class MySQLAnalysisStrategy implements PlatformAnalysisStrategy
{
    private const RECOMMENDED_CHARSET = 'utf8mb4';

    private const RECOMMENDED_COLLATION = 'utf8mb4_unicode_ci';

    private const SUBOPTIMAL_COLLATIONS = [
        'utf8mb4_general_ci',
        'utf8_general_ci',
        'utf8_unicode_ci',
    ];

    private const RECOMMENDED_SQL_MODES = [
        'STRICT_TRANS_TABLES',
        'NO_ZERO_DATE',
        'NO_ZERO_IN_DATE',
        'ERROR_FOR_DIVISION_BY_ZERO',
        'NO_ENGINE_SUBSTITUTION',
    ];

    private const RECOMMENDED_MIN_CONNECTIONS = 100;

    private const RECOMMENDED_MAX_CONNECTIONS = 500;

    /**
     * @return array<mixed>
     */
    /**
     * System/framework tables that can be ignored for charset issues.
     */
    private const SYSTEM_TABLES = [
        'doctrine_migration_versions',
        'migration_versions',
        'migrations',
        'phinxlog',
        'sessions',
        'cache',
        'cache_items',
        'messenger_messages',
    ];

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

    public function analyzeCharset(): iterable
    {
        $databaseName = $this->connection->getDatabase();

        if (null === $databaseName) {
            return;
        }

        $dbCharset         = $this->getDatabaseCharset($databaseName);
        $problematicTables = $this->getTablesWithWrongCharset();

        // Check database charset
        if ('utf8' === $dbCharset || 'utf8mb3' === $dbCharset) {
            yield new DatabaseConfigIssue([
                'title'       => 'Database using utf8 instead of utf8mb4',
                'description' => sprintf(
                    'Database "%s" is using charset "%s" which only supports 3-byte UTF-8. ' .
                    'This causes issues with emojis (😱), some Asian characters, and mathematical symbols. ' .
                    'Use utf8mb4 for full Unicode support.',
                    $databaseName,
                    $dbCharset,
                ),
                'severity'   => 'warning',
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'Database charset',
                    currentValue: $dbCharset,
                    recommendedValue: self::RECOMMENDED_CHARSET,
                    description: 'utf8mb4 supports all Unicode characters including emojis',
                    fixCommand: sprintf('ALTER DATABASE `%s` CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;', $databaseName),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }

        // Check tables charset
        if ([] !== $problematicTables) {
            $tableList = implode(', ', array_slice($problematicTables, 0, 5));

            if (count($problematicTables) > 5) {
                $tableList .= sprintf(' (and %d more)', count($problematicTables) - 5);
            }

            yield new DatabaseConfigIssue([
                'title'       => sprintf('%d tables using utf8 charset', count($problematicTables)),
                'description' => sprintf(
                    'Found %d tables using utf8/utf8mb3 charset: %s. ' .
                    'These tables should use utf8mb4 to support emojis and full Unicode.',
                    count($problematicTables),
                    $tableList,
                ),
                'severity'   => count($problematicTables) > 10 ? 'critical' : 'warning',
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'Table charset',
                    currentValue: 'utf8/utf8mb3',
                    recommendedValue: self::RECOMMENDED_CHARSET,
                    description: 'Convert all tables to utf8mb4',
                    fixCommand: $this->getTableConversionCommand($problematicTables),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }
    }

    public function analyzeCollation(): iterable
    {
        $databaseName = $this->connection->getDatabase();

        if (null === $databaseName) {
            return;
        }

        $dbCollation  = $this->getDatabaseCollation($databaseName);

        // Issue 1: Check database collation
        if ($this->isSuboptimalCollation($dbCollation)) {
            yield $this->createSuboptimalCollationIssue($databaseName, $dbCollation, 'database');
        }

        // Issue 2: Check tables with different collations
        $diffCollationTables = $this->getTablesWithDifferentCollation($databaseName, $dbCollation);

        if ([] !== $diffCollationTables) {
            yield $this->createCollationMismatchIssue($diffCollationTables, $dbCollation);
        }

        // Issue 3: Check for collation mismatches in foreign key columns
        $fkCollationMismatches = $this->getForeignKeyCollationMismatches($databaseName);

        if ([] !== $fkCollationMismatches) {
            yield $this->createForeignKeyCollationIssue($fkCollationMismatches);
        }
    }

    public function analyzeTimezone(): iterable
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

    public function analyzeConnectionPooling(): iterable
    {
        $maxConnections     = $this->getMaxConnections();
        $maxUsedConnections = $this->getMaxUsedConnections();

        // Check if max_connections is too low
        if ($maxConnections < self::RECOMMENDED_MIN_CONNECTIONS) {
            yield new DatabaseConfigIssue([
                'title'       => 'Low max_connections setting',
                'description' => sprintf(
                    'Current max_connections is %d, which is below the recommended minimum of %d. ' .
                    'This may cause "Too many connections" errors during peak load.',
                    $maxConnections,
                    self::RECOMMENDED_MIN_CONNECTIONS,
                ),
                'severity'   => 'warning',
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'max_connections',
                    currentValue: (string) $maxConnections,
                    recommendedValue: (string) self::RECOMMENDED_MIN_CONNECTIONS,
                    description: 'Increase to handle more concurrent connections',
                    fixCommand: $this->getMaxConnectionsFixCommand(self::RECOMMENDED_MIN_CONNECTIONS),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }

        // Check if approaching max_connections
        $utilizationPercent = ($maxUsedConnections / $maxConnections) * 100;

        if ($utilizationPercent > 80) {
            yield new DatabaseConfigIssue([
                'title'       => 'High connection pool utilization',
                'description' => sprintf(
                    'Connection pool utilization is %.1f%% (%d/%d connections used). ' .
                    'You are approaching the max_connections limit. Consider increasing it.',
                    $utilizationPercent,
                    $maxUsedConnections,
                    $maxConnections,
                ),
                'severity'   => $utilizationPercent > 90 ? 'critical' : 'warning',
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'max_connections',
                    currentValue: (string) $maxConnections,
                    recommendedValue: (string) min($maxConnections * 2, self::RECOMMENDED_MAX_CONNECTIONS),
                    description: 'Increase to prevent connection errors during peak load',
                    fixCommand: $this->getMaxConnectionsFixCommand(min($maxConnections * 2, self::RECOMMENDED_MAX_CONNECTIONS)),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }

        // Check wait_timeout
        $waitTimeout = $this->getWaitTimeout();

        if ($waitTimeout > 300) { // > 5 minutes
            yield new DatabaseConfigIssue([
                'title'       => 'High wait_timeout setting',
                'description' => sprintf(
                    'Current wait_timeout is %d seconds (%.1f minutes). ' .
                    'Long idle connections waste resources. Recommended: 60-300 seconds.',
                    $waitTimeout,
                    $waitTimeout / 60,
                ),
                'severity'   => 'info',
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'wait_timeout',
                    currentValue: (string) $waitTimeout,
                    recommendedValue: '180',
                    description: 'Reduce to free up idle connections faster',
                    fixCommand: $this->getWaitTimeoutFixCommand(180),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }
    }

    public function analyzeStrictMode(): iterable
    {
        $sqlMode      = $this->getSqlMode();
        $missingModes = $this->getMissingModes($sqlMode);

        if ([] !== $missingModes) {
            yield new DatabaseConfigIssue([
                'title'       => 'Missing SQL Strict Mode Settings',
                'description' => sprintf(
                    'Your database is missing important SQL modes: %s. ' .
                    'These modes prevent silent data truncation and invalid data insertion.',
                    implode(', ', $missingModes),
                ),
                'severity'   => count($missingModes) >= 3 ? 'critical' : 'warning',
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'sql_mode',
                    currentValue: $sqlMode,
                    recommendedValue: implode(',', self::RECOMMENDED_SQL_MODES),
                    description: 'Add missing modes to prevent data corruption and ensure data integrity',
                    fixCommand: $this->getFixCommand($sqlMode, $missingModes),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }
    }

    public function supportsFeature(string $feature): bool
    {
        return match ($feature) {
            'charset'     => true,
            'collation'   => true,
            'timezone'    => true,
            'pooling'     => true,
            'strict_mode' => true,
            default       => false,
        };
    }

    public function getPlatformName(): string
    {
        return $this->databasePlatformDetector->isMariaDB() ? 'mariadb' : 'mysql';
    }

    // Private helper methods (migrated from original analyzers)

    private function getDatabaseCharset(string $databaseName): string
    {
        $result = $this->connection->executeQuery(
            'SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$databaseName],
        );

        $row = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['DEFAULT_CHARACTER_SET_NAME'] ?? 'unknown';
    }

    private function getTablesWithWrongCharset(): array
    {
        $databaseName = $this->connection->getDatabase();
        $result       = $this->connection->executeQuery(
            "SELECT TABLE_NAME, TABLE_COLLATION
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ?
             AND (TABLE_COLLATION LIKE 'utf8_%' OR TABLE_COLLATION LIKE 'utf8mb3_%')
             AND TABLE_COLLATION NOT LIKE 'utf8mb4_%'",
            [$databaseName],
        );

        $tables = $this->databasePlatformDetector->fetchAllAssociative($result);
        $tableNames = array_column($tables, 'TABLE_NAME');

        // Filter out system tables
        return array_filter($tableNames, function (string $tableName): bool {
            foreach (self::SYSTEM_TABLES as $systemTable) {
                if (str_contains(strtolower($tableName), strtolower($systemTable))) {
                    return false;
                }
            }
            return true;
        });
    }

    private function getTableConversionCommand(array $tables): string
    {
        $commands = [];

        foreach (array_slice($tables, 0, 10) as $table) {
            $commands[] = sprintf('ALTER TABLE `%s` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;', $table);
        }

        if (count($tables) > 10) {
            $commands[] = '-- ... and ' . (count($tables) - 10) . ' more tables';
        }

        return implode("
", $commands);
    }

    private function getDatabaseCollation(string $databaseName): string
    {
        $result = $this->connection->executeQuery(
            'SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$databaseName],
        );

        $row = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['DEFAULT_COLLATION_NAME'] ?? 'unknown';
    }

    private function isSuboptimalCollation(string $collation): bool
    {
        return in_array($collation, self::SUBOPTIMAL_COLLATIONS, true);
    }

    /**
     * @return array<mixed>
     */
    private function getTablesWithDifferentCollation(string $databaseName, string $dbCollation): array
    {
        $result = $this->connection->executeQuery(
            "SELECT TABLE_NAME, TABLE_COLLATION
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ?
             AND TABLE_COLLATION != ?
             AND TABLE_TYPE = 'BASE TABLE'",
            [$databaseName, $dbCollation],
        );

        return $this->databasePlatformDetector->fetchAllAssociative($result);
    }

    /**
     * @return array<mixed>
     */
    private function getForeignKeyCollationMismatches(string $databaseName): array
    {
        $query = <<<SQL
            SELECT
                kcu.TABLE_NAME as child_table,
                kcu.COLUMN_NAME as child_column,
                col1.COLLATION_NAME as child_collation,
                kcu.REFERENCED_TABLE_NAME as parent_table,
                kcu.REFERENCED_COLUMN_NAME as parent_column,
                col2.COLLATION_NAME as parent_collation
            FROM information_schema.KEY_COLUMN_USAGE kcu
            JOIN information_schema.COLUMNS col1
                ON kcu.TABLE_SCHEMA = col1.TABLE_SCHEMA
                AND kcu.TABLE_NAME = col1.TABLE_NAME
                AND kcu.COLUMN_NAME = col1.COLUMN_NAME
            JOIN information_schema.COLUMNS col2
                ON kcu.REFERENCED_TABLE_SCHEMA = col2.TABLE_SCHEMA
                AND kcu.REFERENCED_TABLE_NAME = col2.TABLE_NAME
                AND kcu.REFERENCED_COLUMN_NAME = col2.COLUMN_NAME
            WHERE kcu.TABLE_SCHEMA = ?
            AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            AND col1.COLLATION_NAME IS NOT NULL
            AND col2.COLLATION_NAME IS NOT NULL
            AND col1.COLLATION_NAME != col2.COLLATION_NAME
            SQL;

        $result = $this->connection->executeQuery($query, [$databaseName]);

        return $this->databasePlatformDetector->fetchAllAssociative($result);
    }

    private function createSuboptimalCollationIssue(string $databaseName, string $currentCollation, string $level): DatabaseConfigIssue
    {
        $description = sprintf(
            '%s "%s" is using collation "%s". ' .
            'This is a valid choice with trade-offs: ' .
            'utf8mb4_general_ci is **faster** but less accurate for Unicode sorting (e.g., "ä" vs "a"). ' .
            'utf8mb4_unicode_ci is more accurate for multilingual sorting but slightly slower. ' .
            'utf8mb4_0900_ai_ci (MySQL 8.0+) offers best of both worlds.',
            ucfirst($level),
            $databaseName,
            $currentCollation,
        );

        $fixCommand = 'database' === $level
            ? sprintf('ALTER DATABASE `%s` COLLATE = ', $databaseName) . self::RECOMMENDED_COLLATION . ';'
            : sprintf('ALTER TABLE `%s` COLLATE = ', $databaseName) . self::RECOMMENDED_COLLATION . ';';

        return new DatabaseConfigIssue([
            'title'       => sprintf('%s using collation: %s (performance vs accuracy trade-off)', ucfirst($level), $currentCollation),
            'description' => $description,
            'severity'    => 'info', // INFO instead of WARNING (it's a valid choice)
            'suggestion'  => $this->suggestionFactory->createConfiguration(
                setting: ucfirst($level) . ' collation',
                currentValue: $currentCollation,
                recommendedValue: self::RECOMMENDED_COLLATION,
                description: 'utf8mb4_unicode_ci provides accurate Unicode sorting. Only change if multilingual sorting is important.',
                fixCommand: $fixCommand,
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function createCollationMismatchIssue(array $tables, string $dbCollation): DatabaseConfigIssue
    {
        $tableList     = array_slice($tables, 0, 5);
        $tableNames    = array_map(fn (array $table): string => sprintf('%s (%s)', $table['TABLE_NAME'], $table['TABLE_COLLATION']), $tableList);
        $tableNamesStr = implode(', ', $tableNames);

        if (count($tables) > 5) {
            $tableNamesStr .= sprintf(' (and %d more)', count($tables) - 5);
        }

        // Check if all tables use the SAME collation (homogeneous but different from DB)
        $uniqueCollations = array_unique(array_column($tables, 'TABLE_COLLATION'));
        $isHomogeneous = 1 === count($uniqueCollations);
        $commonCollation = $isHomogeneous ? reset($uniqueCollations) : null;

        $fixCommands = [];

        foreach (array_slice($tables, 0, 10) as $table) {
            $fixCommands[] = sprintf(
                'ALTER TABLE `%s` COLLATE = %s;',
                $table['TABLE_NAME'],
                $dbCollation,
            );
        }

        if (count($tables) > 10) {
            $fixCommands[] = '-- ... and ' . (count($tables) - 10) . ' more tables';
        }

        // Determine severity and description based on homogeneity
        if ($isHomogeneous) {
            // All tables use the same collation (intentional)
            $severity = 'info';
            $description = sprintf(
                'Found %d tables ALL using collation "%s" while database default is "%s". ' .
                'This appears to be intentional (consistent). Only problematic if JOINing with tables using "%s".',
                count($tables),
                $commonCollation,
                $dbCollation,
                $dbCollation,
            );
        } else {
            // Mixed collations (real problem)
            $severity = count($tables) > 10 ? 'warning' : 'info';
            $description = sprintf(
                'Found %d tables with MIXED collations different from database default (%s): %s. ' .
                'This can cause performance issues in JOINs and unexpected sorting behavior.',
                count($tables),
                $dbCollation,
                $tableNamesStr,
            );
        }

        return new DatabaseConfigIssue([
            'title'       => sprintf('%d tables with different collation than database', count($tables)),
            'description' => $description,
            'severity'   => $severity,
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'Table collations',
                currentValue: $isHomogeneous ? $commonCollation : 'Mixed collations',
                recommendedValue: $dbCollation,
                description: $isHomogeneous
                    ? 'Tables use consistent collation, only different from database default'
                    : 'Unify table collations to match database default for consistent behavior',
                fixCommand: implode("
", $fixCommands),
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function createForeignKeyCollationIssue(array $mismatches): DatabaseConfigIssue
    {
        $mismatchList = array_slice($mismatches, 0, 3);
        $descriptions = array_map(
            fn (array $mismatch): string => sprintf(
                '%s.%s (%s) -> %s.%s (%s)',
                $mismatch['child_table'],
                $mismatch['child_column'],
                $mismatch['child_collation'],
                $mismatch['parent_table'],
                $mismatch['parent_column'],
                $mismatch['parent_collation'],
            ),
            $mismatchList,
        );
        $descriptionStr = implode("
- ", $descriptions);

        if (count($mismatches) > 3) {
            $descriptionStr .= sprintf("
- ... and %d more", count($mismatches) - 3);
        }

        $fixCommands = [];

        foreach (array_slice($mismatches, 0, 5) as $mismatch) {
            $fixCommands[] = sprintf(
                'ALTER TABLE `%s` MODIFY COLUMN `%s` VARCHAR(255) COLLATE %s; -- Match parent table',
                $mismatch['child_table'],
                $mismatch['child_column'],
                $mismatch['parent_collation'],
            );
        }

        if (count($mismatches) > 5) {
            $fixCommands[] = '-- ... and ' . (count($mismatches) - 5) . ' more columns';
        }

        return new DatabaseConfigIssue([
            'title'       => sprintf('%d foreign key collation mismatches detected', count($mismatches)),
            'description' => sprintf(
                'Found %d foreign key relationships where child and parent columns have different collations. ' .
                'This prevents index usage in JOINs and causes severe performance degradation:' . "
- " . $descriptionStr,
                count($mismatches),
            ),
            'severity'   => 'critical',
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'Foreign key collations',
                currentValue: 'Mismatched collations',
                recommendedValue: 'Matching collations',
                description: 'Foreign key columns MUST have the same collation as their referenced columns for optimal JOIN performance',
                fixCommand: implode("
", $fixCommands),
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
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
            'severity'   => 'warning',
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
                'This mismatch causes subtle bugs:' . "
" .
                '- DateTime values saved from PHP are stored with wrong timezone' . "
" .
                '- Queries with NOW(), CURDATE() return different times than PHP' . "
" .
                '- Date comparisons between PHP and MySQL fail' . "
" .
                '- Reports and analytics show incorrect timestamps',
                $mysqlTz,
                $phpTz,
            ),
            'severity'   => 'critical',
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
            'severity'   => 'warning',
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

    private function getMaxConnections(): int
    {
        $result = $this->connection->executeQuery("SHOW VARIABLES LIKE 'max_connections'");
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return (int) ($row['Value'] ?? 151);
    }

    private function getMaxUsedConnections(): int
    {
        $result = $this->connection->executeQuery("SHOW STATUS LIKE 'Max_used_connections'");
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return (int) ($row['Value'] ?? 0);
    }

    private function getWaitTimeout(): int
    {
        $result = $this->connection->executeQuery("SHOW VARIABLES LIKE 'wait_timeout'");
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return (int) ($row['Value'] ?? 28800);
    }

    private function getMaxConnectionsFixCommand(int $recommended): string
    {
        return "-- In your MySQL configuration file (my.cnf or my.ini):
" .
               "[mysqld]
" .
               "max_connections = {$recommended}

" .
               "-- Or set it globally (requires SUPER privilege and restart):
" .
               "SET GLOBAL max_connections = {$recommended};

" .
               '-- Note: Restart MySQL for persistent changes';
    }

    private function getWaitTimeoutFixCommand(int $recommended): string
    {
        return "-- In your MySQL configuration file (my.cnf or my.ini):
" .
               "[mysqld]
" .
               sprintf('wait_timeout = %d%s', $recommended, PHP_EOL) .
               "interactive_timeout = {$recommended}

" .
               "-- Or set it globally:
" .
               "SET GLOBAL wait_timeout = {$recommended};
" .
               sprintf('SET GLOBAL interactive_timeout = %d;', $recommended);
    }

    private function getSqlMode(): string
    {
        $result = $this->connection->executeQuery("SHOW VARIABLES LIKE 'sql_mode'");
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['Value'] ?? '';
    }

    /**
     * @return array<mixed>
     */
    private function getMissingModes(string $currentMode): array
    {
        $activeModes = array_map(function ($mode) {
            return trim($mode);
        }, explode(',', strtoupper($currentMode)));
        $missing     = [];

        foreach (self::RECOMMENDED_SQL_MODES as $mode) {
            if (!in_array($mode, $activeModes, true)) {
                $missing[] = $mode;
            }
        }

        return $missing;
    }

    private function getFixCommand(string $currentMode, array $missingModes): string
    {
        $allModes = array_merge(
            array_filter(array_map(function ($mode) {
                return trim($mode);
            }, explode(',', $currentMode)), fn (string $mode): bool => '' !== $mode),
            $missingModes,
        );
        $newMode = implode(',', array_unique($allModes));

        return "-- In your MySQL configuration file (my.cnf or my.ini):
" .
               "[mysqld]
" .
               "sql_mode = '{$newMode}'

" .
               "-- Or set it dynamically (session only):
" .
               "SET SESSION sql_mode = '{$newMode}';

" .
               "-- Or globally (requires SUPER privilege):
" .
               sprintf("SET GLOBAL sql_mode = '%s';", $newMode);
    }
}
