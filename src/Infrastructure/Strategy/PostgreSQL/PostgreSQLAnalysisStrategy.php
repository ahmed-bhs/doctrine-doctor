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
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\PlatformAnalysisStrategy;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/**
 * Analysis strategy for PostgreSQL platform.
 * Implements PostgreSQL-specific analysis logic for:
 * - Encoding configuration (UTF8, SQL_ASCII, client_encoding)
 * - Collation settings ("C" vs locale-aware, libc vs ICU)
 * - Timezone configuration + TIMESTAMP vs TIMESTAMPTZ detection
 * - Connection pooling (max_connections, idle_in_transaction_session_timeout)
 * - Strict mode settings (standard_conforming_strings, etc.)
 */
class PostgreSQLAnalysisStrategy implements PlatformAnalysisStrategy
{
    private const RECOMMENDED_ENCODING = 'UTF8';

    private const PROBLEMATIC_ENCODINGS = ['SQL_ASCII', 'LATIN1', 'WIN1252'];

    private const RECOMMENDED_MIN_CONNECTIONS = 100; // 5 minutes in milliseconds

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
        $databaseName   = $this->connection->getDatabase();

        if (null === $databaseName) {
            return;
        }

        $serverEncoding = $this->getServerEncoding();
        $clientEncoding = $this->getClientEncoding();

        // Issue 1: Database using problematic encoding (SQL_ASCII, LATIN1, etc.)
        if (in_array($serverEncoding, self::PROBLEMATIC_ENCODINGS, true)) {
            yield $this->createProblematicEncodingIssue($databaseName, $serverEncoding);
        }

        // Issue 2: Mismatch between server and client encoding
        if ($serverEncoding !== $clientEncoding) {
            yield $this->createEncodingMismatchIssue($serverEncoding, $clientEncoding);
        }

        // Issue 3: Check template databases encoding
        $templatesWithBadEncoding = $this->getTemplateDatabasesWithBadEncoding();

        if ([] !== $templatesWithBadEncoding) {
            yield $this->createTemplateEncodingIssue($templatesWithBadEncoding);
        }
    }

    public function analyzeCollation(): iterable
    {
        $databaseName = $this->connection->getDatabase();

        if (null === $databaseName) {
            return;
        }

        $dbCollation  = $this->getDatabaseCollation();
        $dbCtype      = $this->getDatabaseCtype();

        // Issue 1: Database using "C" collation (byte-order, not locale-aware)
        if ('C' === $dbCollation || 'POSIX' === $dbCollation) {
            yield $this->createByteOrderCollationIssue($databaseName, $dbCollation);
        }

        // Issue 2: Collation != Ctype (unusual and potentially problematic)
        if ($dbCollation !== $dbCtype) {
            yield $this->createCollationCtypeMismatchIssue($dbCollation, $dbCtype);
        }

        // Issue 3: Check for column-level collation mismatches in FK relationships
        $fkCollationMismatches = $this->getForeignKeyCollationMismatches();

        if ([] !== $fkCollationMismatches) {
            yield $this->createForeignKeyCollationIssue($fkCollationMismatches);
        }

        // Issue 4: Check if using ICU collations (available PostgreSQL 10+)
        $hasICUCollations = $this->hasICUCollationSupport();

        if ($hasICUCollations && !$this->isUsingICUCollations()) {
            yield $this->createICUCollationSuggestionIssue();
        }
    }

    public function analyzeTimezone(): iterable
    {
        $pgTimezone  = $this->getPostgreSQLTimezone();
        $phpTimezone = $this->getPHPTimezone();

        // Issue 1: PostgreSQL timezone != PHP timezone
        if ($this->timezonesAreDifferent($pgTimezone, $phpTimezone)) {
            yield $this->createTimezoneMismatchIssue($pgTimezone, $phpTimezone);
        }

        // Issue 2: Using "localtime" timezone (ambiguous)
        if ('localtime' === strtolower($pgTimezone)) {
            yield $this->createLocaltimeWarningIssue();
        }

        // Issue 3: CRITICAL - Tables using TIMESTAMP without timezone
        $tablesWithoutTZ = $this->getTablesUsingTimestampWithoutTimezone();

        if ([] !== $tablesWithoutTZ) {
            yield $this->createTimestampWithoutTimezoneIssue($tablesWithoutTZ);
        }
    }

    public function analyzeConnectionPooling(): iterable
    {
        $maxConnections           = $this->getMaxConnections();
        $currentConnections       = $this->getCurrentConnections();
        $idleInTransactionTimeout = $this->getIdleInTransactionSessionTimeout();
        $statementTimeout         = $this->getStatementTimeout();

        // Issue 1: max_connections too low
        if ($maxConnections < self::RECOMMENDED_MIN_CONNECTIONS) {
            yield new DatabaseConfigIssue([
                'title'       => 'Low max_connections setting',
                'description' => sprintf(
                    'Current max_connections is %d, which is below the recommended minimum of %d. ' .
                    'Note: PostgreSQL uses ~10MB RAM per connection vs MySQL ~200KB. ' .
                    'Consider using pgbouncer for connection pooling.',
                    $maxConnections,
                    self::RECOMMENDED_MIN_CONNECTIONS,
                ),
                'severity'   => 'warning',
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'max_connections',
                    currentValue: (string) $maxConnections,
                    recommendedValue: (string) self::RECOMMENDED_MIN_CONNECTIONS,
                    description: 'Increase max_connections or use pgbouncer for pooling',
                    fixCommand: $this->getMaxConnectionsFixCommand(self::RECOMMENDED_MIN_CONNECTIONS),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }

        // Issue 2: High connection utilization
        $utilizationPercent = ($currentConnections / $maxConnections) * 100;

        if ($utilizationPercent > 80) {
            yield new DatabaseConfigIssue([
                'title'       => 'High connection pool utilization',
                'description' => sprintf(
                    'Connection pool utilization is %.1f%% (%d/%d connections). ' .
                    'PostgreSQL connections consume more RAM than MySQL. ' .
                    'Strongly recommend using pgbouncer or pgpool for connection pooling.',
                    $utilizationPercent,
                    $currentConnections,
                    $maxConnections,
                ),
                'severity'   => $utilizationPercent > 90 ? 'critical' : 'warning',
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'Connection pooling',
                    currentValue: sprintf('%d active / %d max', $currentConnections, $maxConnections),
                    recommendedValue: 'Use pgbouncer',
                    description: 'Implement connection pooling to reduce resource usage',
                    fixCommand: $this->getPgbouncerRecommendation(),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }

        // Issue 3: CRITICAL - idle_in_transaction_session_timeout = 0 (no timeout!)
        if (0 === $idleInTransactionTimeout) {
            yield new DatabaseConfigIssue([
                'title'       => 'No timeout for idle transactions (CRITICAL)',
                'description' => 'idle_in_transaction_session_timeout is 0 (disabled). ' .
                    'This allows transactions to stay open indefinitely, causing: ' .
                    '- Table locks that block other queries' . "
" .
                    '- VACUUM blocked (table bloat)' . "
" .
                    '- Connection pool exhaustion' . "
" .
                    '- Memory leaks in long-running transactions',
                'severity'   => 'critical',
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'idle_in_transaction_session_timeout',
                    currentValue: '0 (disabled)',
                    recommendedValue: '300000 (5 minutes)',
                    description: 'Set timeout to automatically kill idle transactions',
                    fixCommand: $this->getIdleInTransactionTimeoutFixCommand(),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }

        // Issue 4: statement_timeout = 0 (no query timeout)
        if (0 === $statementTimeout) {
            yield new DatabaseConfigIssue([
                'title'       => 'No statement timeout configured',
                'description' => 'statement_timeout is 0 (disabled). ' .
                    'Long-running queries can block the database and exhaust resources. ' .
                    'Recommended: 30-60 seconds for web apps.',
                'severity'   => 'warning',
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'statement_timeout',
                    currentValue: '0 (disabled)',
                    recommendedValue: '30000 (30 seconds)',
                    description: 'Set timeout to prevent runaway queries',
                    fixCommand: $this->getStatementTimeoutFixCommand(),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }

        // Issue 5: Check for idle connections
        $idleConnections = $this->getIdleConnections();

        if (count($idleConnections) > 10) {
            yield $this->createIdleConnectionsIssue($idleConnections);
        }
    }

    public function analyzeStrictMode(): iterable
    {
        $standardConformingStrings = $this->getStandardConformingStrings();
        $checkFunctionBodies       = $this->getCheckFunctionBodies();

        // Issue 1: standard_conforming_strings = off (old, dangerous behavior)
        if ('off' === $standardConformingStrings) {
            yield new DatabaseConfigIssue([
                'title'       => 'Non-standard string escaping enabled (security risk)',
                'description' => 'standard_conforming_strings is OFF. ' .
                    'This enables legacy backslash escaping which can cause SQL injection vulnerabilities. ' .
                    'PostgreSQL (9.1+) uses standard-compliant string escaping by default.',
                'severity'   => 'critical',
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'standard_conforming_strings',
                    currentValue: 'off',
                    recommendedValue: 'on',
                    description: 'Enable standard-compliant string escaping for security',
                    fixCommand: "-- In postgresql.conf:
standard_conforming_strings = on

-- Or set globally:
ALTER DATABASE your_db SET standard_conforming_strings = on;",
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }

        // Issue 2: check_function_bodies = off (skips validation)
        if ('off' === $checkFunctionBodies) {
            yield new DatabaseConfigIssue([
                'title'       => 'Function body validation disabled',
                'description' => 'check_function_bodies is OFF. ' .
                    'This skips validation when creating functions, allowing invalid code to be stored. ' .
                    'Recommended to enable for catching errors early.',
                'severity'   => 'warning',
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'check_function_bodies',
                    currentValue: 'off',
                    recommendedValue: 'on',
                    description: 'Enable function validation to catch errors during creation',
                    fixCommand: "-- In postgresql.conf:
check_function_bodies = on

-- Or set globally:
ALTER DATABASE your_db SET check_function_bodies = on;",
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
        return 'postgresql';
    }

    // Private helper methods - PostgreSQL specific

    private function getServerEncoding(): string
    {
        $result = $this->connection->executeQuery('SHOW server_encoding');
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['server_encoding'] ?? 'unknown';
    }

    private function getClientEncoding(): string
    {
        $result = $this->connection->executeQuery('SHOW client_encoding');
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['client_encoding'] ?? 'unknown';
    }

    /**
     * @return array<mixed>
     */
    private function getTemplateDatabasesWithBadEncoding(): array
    {
        $sql = <<<SQL_WRAP
            SELECT datname, pg_encoding_to_char(encoding) as encoding
            FROM pg_database
            WHERE datistemplate = true
              AND pg_encoding_to_char(encoding) IN ('SQL_ASCII', 'LATIN1', 'WIN1252')
        SQL_WRAP;

        $result = $this->connection->executeQuery($sql);

        return $this->databasePlatformDetector->fetchAllAssociative($result);
    }

    private function createProblematicEncodingIssue(string $databaseName, string $encoding): DatabaseConfigIssue
    {
        $description = sprintf(
            'Database "%s" is using encoding "%s" which is problematic. ',
            $databaseName,
            $encoding,
        );

        if ('SQL_ASCII' === $encoding) {
            $description .= 'SQL_ASCII is a "catch-all" encoding that accepts any byte sequence without validation. ' .
                'This leads to corrupt data, inconsistent sorting, and encoding errors. ' .
                'ALWAYS use UTF8 for new databases.';
        } else {
            $description .= sprintf(
                '%s is a legacy single-byte encoding that cannot handle international characters. ' .
                'Use UTF8 for full Unicode support.',
                $encoding,
            );
        }

        return new DatabaseConfigIssue([
            'title'       => sprintf('Database using problematic encoding: %s', $encoding),
            'description' => $description,
            'severity'    => 'SQL_ASCII' === $encoding ? 'critical' : 'warning',
            'suggestion'  => $this->suggestionFactory->createConfiguration(
                setting: 'Database encoding',
                currentValue: $encoding,
                recommendedValue: self::RECOMMENDED_ENCODING,
                description: 'Recreate database with UTF8 encoding',
                fixCommand: sprintf(
                    "-- Encoding cannot be changed after database creation.
" .
                    "-- You must dump, drop, recreate, and restore:

" .
                    "pg_dump -U user %s > backup.sql
" .
                    "DROP DATABASE %s;
" .
                    "CREATE DATABASE %s ENCODING 'UTF8' LC_COLLATE='en_US.UTF-8' LC_CTYPE='en_US.UTF-8';
" .
                    'psql -U user %s < backup.sql',
                    $databaseName,
                    $databaseName,
                    $databaseName,
                    $databaseName,
                ),
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function createEncodingMismatchIssue(string $serverEncoding, string $clientEncoding): DatabaseConfigIssue
    {
        return new DatabaseConfigIssue([
            'title'       => 'Encoding mismatch between server and client',
            'description' => sprintf(
                'Server encoding is "%s" but client encoding is "%s". ' .
                'This mismatch can cause character corruption when data is transferred between client and server. ' .
                'Both should be UTF8 for consistency.',
                $serverEncoding,
                $clientEncoding,
            ),
            'severity'   => 'warning',
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'client_encoding',
                currentValue: $clientEncoding,
                recommendedValue: $serverEncoding,
                description: 'Set client_encoding to match server_encoding',
                fixCommand: "-- In postgresql.conf or per-connection:
SET client_encoding = '{$serverEncoding}';

-- In Doctrine DBAL:
doctrine:
    dbal:
        options:
            client_encoding: '{$serverEncoding}'",
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function createTemplateEncodingIssue(array $templates): DatabaseConfigIssue
    {
        $templateList = implode(', ', array_column($templates, 'datname'));

        return new DatabaseConfigIssue([
            'title'       => 'Template databases with problematic encoding',
            'description' => sprintf(
                'Template databases %s have problematic encodings. ' .
                'New databases created from these templates will inherit the bad encoding. ' .
                'Fix template1 to prevent future issues.',
                $templateList,
            ),
            'severity'   => 'warning',
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'Template database encoding',
                currentValue: 'Problematic encodings',
                recommendedValue: 'UTF8',
                description: 'Recreate template1 with UTF8 encoding',
                fixCommand: "-- Recreate template1 (requires superuser):
" .
                    "UPDATE pg_database SET datistemplate = FALSE WHERE datname = 'template1';
" .
                    "DROP DATABASE template1;
" .
                    "CREATE DATABASE template1 WITH TEMPLATE = template0 ENCODING = 'UTF8' LC_COLLATE = 'en_US.UTF-8' LC_CTYPE = 'en_US.UTF-8';
" .
                    "UPDATE pg_database SET datistemplate = TRUE WHERE datname = 'template1';",
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function getDatabaseCollation(): string
    {
        $sql    = 'SELECT datcollate FROM pg_database WHERE datname = current_database()';
        $result = $this->connection->executeQuery($sql);
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['datcollate'] ?? 'unknown';
    }

    private function getDatabaseCtype(): string
    {
        $sql    = 'SELECT datctype FROM pg_database WHERE datname = current_database()';
        $result = $this->connection->executeQuery($sql);
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['datctype'] ?? 'unknown';
    }

    private function createByteOrderCollationIssue(string $databaseName, string $collation): DatabaseConfigIssue
    {
        return new DatabaseConfigIssue([
            'title'       => sprintf('Database using "%s" collation (byte-order, not locale-aware)', $collation),
            'description' => sprintf(
                'Database "%s" uses "%s" collation which sorts by byte values, not linguistic rules. ' .
                'This causes incorrect sorting for non-ASCII characters (e.g., "ä" sorts after "z"). ' .
                'Use a locale-aware collation like "en_US.UTF-8" for proper internationalization.',
                $databaseName,
                $collation,
            ),
            'severity'   => 'warning',
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'Database collation',
                currentValue: $collation,
                recommendedValue: 'en_US.UTF-8 (or your locale)',
                description: 'Recreate database with locale-aware collation',
                fixCommand: sprintf(
                    "-- Collation cannot be changed after creation. Dump and recreate:

" .
                    "pg_dump -U user %s > backup.sql
" .
                    "DROP DATABASE %s;
" .
                    "CREATE DATABASE %s ENCODING 'UTF8' LC_COLLATE='en_US.UTF-8' LC_CTYPE='en_US.UTF-8';
" .
                    'psql -U user %s < backup.sql',
                    $databaseName,
                    $databaseName,
                    $databaseName,
                    $databaseName,
                ),
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function createCollationCtypeMismatchIssue(string $collation, string $ctype): DatabaseConfigIssue
    {
        return new DatabaseConfigIssue([
            'title'       => 'Database collation and ctype mismatch',
            'description' => sprintf(
                'Database has collation "%s" but ctype "%s". ' .
                'These should normally match for consistent behavior. ' .
                'Mismatches can cause unexpected sorting and case conversion issues.',
                $collation,
                $ctype,
            ),
            'severity'   => 'info',
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'Collation/Ctype',
                currentValue: sprintf('LC_COLLATE=%s, LC_CTYPE=%s', $collation, $ctype),
                recommendedValue: 'Both should match (e.g., both en_US.UTF-8)',
                description: 'Use matching collation and ctype when creating databases',
                fixCommand: "-- For future databases:
CREATE DATABASE newdb ENCODING 'UTF8' LC_COLLATE='en_US.UTF-8' LC_CTYPE='en_US.UTF-8';",
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    /**
     * @return array<mixed>
     */
    private function getForeignKeyCollationMismatches(): array
    {
        $sql = <<<SQL
                SELECT
                    tc.table_name as child_table,
                    kcu.column_name as child_column,
                    ccu.table_name as parent_table,
                    ccu.column_name as parent_column,
                    c1.collation_name as child_collation,
                    c2.collation_name as parent_collation
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name
                    AND tc.table_schema = kcu.table_schema
                JOIN information_schema.constraint_column_usage ccu
                    ON ccu.constraint_name = tc.constraint_name
                    AND ccu.table_schema = tc.table_schema
                JOIN information_schema.columns c1
                    ON c1.table_name = tc.table_name
                    AND c1.column_name = kcu.column_name
                JOIN information_schema.columns c2
                    ON c2.table_name = ccu.table_name
                    AND c2.column_name = ccu.column_name
                WHERE tc.constraint_type = 'FOREIGN KEY'
                  AND tc.table_schema = 'public'
                  AND c1.collation_name IS NOT NULL
                  AND c2.collation_name IS NOT NULL
                  AND c1.collation_name != c2.collation_name
            SQL;

        $result = $this->connection->executeQuery($sql);

        return $this->databasePlatformDetector->fetchAllAssociative($result);
    }

    private function createForeignKeyCollationIssue(array $mismatches): DatabaseConfigIssue
    {
        $mismatchList = array_slice($mismatches, 0, 3);
        $descriptions = array_map(
            fn (array $mismatch): string => sprintf(
                '%s.%s (%s) -> %s.%s (%s)',
                $mismatch['child_table'],
                $mismatch['child_column'],
                $mismatch['child_collation'] ?? 'default',
                $mismatch['parent_table'],
                $mismatch['parent_column'],
                $mismatch['parent_collation'] ?? 'default',
            ),
            $mismatchList,
        );
        $descriptionStr = implode("
- ", $descriptions);

        if (count($mismatches) > 3) {
            $descriptionStr .= sprintf("
- ... and %d more", count($mismatches) - 3);
        }

        return new DatabaseConfigIssue([
            'title'       => sprintf('%d foreign key collation mismatches detected', count($mismatches)),
            'description' => sprintf(
                'Found %d foreign key relationships where child and parent columns have different collations. ' .
                'This can cause performance issues and query failures:' . "
- " . $descriptionStr,
                count($mismatches),
            ),
            'severity'   => 'warning',
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'Foreign key collations',
                currentValue: 'Mismatched collations',
                recommendedValue: 'Matching collations',
                description: 'Alter columns to use matching collations',
                fixCommand: "-- Example fix (adjust data type as needed):
ALTER TABLE child_table ALTER COLUMN child_column TYPE varchar(255) COLLATE \"en_US.UTF-8\";",
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function hasICUCollationSupport(): bool
    {
        try {
            $sql    = "SELECT COUNT(*) as count FROM pg_collation WHERE collprovider = 'i' LIMIT 1";
            $result = $this->connection->executeQuery($sql);
            $row    = $this->databasePlatformDetector->fetchAssociative($result);

            return ($row['count'] ?? 0) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isUsingICUCollations(): bool
    {
        try {
            $sql = <<<SQL
                    SELECT datcollation, datcollversion
                    FROM pg_database
                    WHERE datname = current_database()
                      AND datcollation LIKE 'und-%'
                SQL;

            $result = $this->connection->executeQuery($sql);
            $row    = $this->databasePlatformDetector->fetchAssociative($result);

            return false !== $row;
        } catch (\Throwable) {
            return false;
        }
    }

    private function createICUCollationSuggestionIssue(): DatabaseConfigIssue
    {
        return new DatabaseConfigIssue([
            'title'       => 'Consider using ICU collations (PostgreSQL 10+)',
            'description' => 'Your PostgreSQL version supports ICU collations, but you are using libc provider. ' .
                'ICU collations provide: ' . "
" .
                '- Better Unicode support' . "
" .
                '- Consistent collation across platforms' . "
" .
                '- Deterministic versions (no breaking changes)' . "
" .
                'Consider using ICU for new databases.',
            'severity'   => 'info',
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'Collation provider',
                currentValue: 'libc',
                recommendedValue: 'ICU',
                description: 'Use ICU collations for new databases',
                fixCommand: "-- For new databases (PostgreSQL 15+):
CREATE DATABASE newdb LOCALE_PROVIDER = icu ICU_LOCALE = 'en-US';",
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function getPostgreSQLTimezone(): string
    {
        $result = $this->connection->executeQuery('SHOW timezone');
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['timezone'] ?? $row['TimeZone'] ?? 'UTC';
    }

    private function getPHPTimezone(): string
    {
        return date_default_timezone_get();
    }

    private function timezonesAreDifferent(string $tz1, string $tz2): bool
    {
        $normalize = function (string $timezone): string {
            try {
                $dateTimeZone = new DateTimeZone($timezone);

                return $dateTimeZone->getName();
            } catch (\Exception) {
                return $timezone;
            }
        };

        $normalized1 = $normalize($tz1);
        $normalized2 = $normalize($tz2);

        $utcEquivalents = ['UTC', 'GMT', 'Etc/UTC'];

        if (in_array($normalized1, $utcEquivalents, true) && in_array($normalized2, $utcEquivalents, true)) {
            return false;
        }

        return $normalized1 !== $normalized2;
    }

    private function createTimezoneMismatchIssue(string $pgTz, string $phpTz): DatabaseConfigIssue
    {
        return new DatabaseConfigIssue([
            'title'       => 'Timezone mismatch between PostgreSQL and PHP',
            'description' => sprintf(
                'PostgreSQL timezone is "%s" but PHP timezone is "%s". ' .
                'This mismatch causes subtle bugs:' . "
" .
                '- DateTime values converted incorrectly' . "
" .
                '- NOW(), CURRENT_TIMESTAMP return different times than PHP' . "
" .
                '- Date comparisons fail' . "
" .
                'Always use UTC for storage and convert in application layer.',
                $pgTz,
                $phpTz,
            ),
            'severity'   => 'critical',
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'Timezone configuration',
                currentValue: sprintf('PostgreSQL: %s, PHP: %s', $pgTz, $phpTz),
                recommendedValue: 'Both use UTC',
                description: 'Synchronize PostgreSQL and PHP timezones',
                fixCommand: $this->getTimezoneFixCommand($phpTz),
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function createLocaltimeWarningIssue(): DatabaseConfigIssue
    {
        return new DatabaseConfigIssue([
            'title'       => 'PostgreSQL using "localtime" timezone (ambiguous)',
            'description' => 'PostgreSQL is configured to use "localtime" which depends on the server\'s system timezone. ' .
                'This is ambiguous and can change if the server timezone changes. ' .
                'Explicitly set to UTC or a named timezone (e.g., "America/New_York").',
            'severity'   => 'warning',
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'PostgreSQL timezone',
                currentValue: 'localtime',
                recommendedValue: 'UTC',
                description: 'Set explicit timezone',
                fixCommand: $this->getTimezoneFixCommand('UTC'),
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    /**
     * @return array<mixed>
     */
    private function getTablesUsingTimestampWithoutTimezone(): array
    {
        $sql = <<<SQL
                SELECT table_name, column_name, data_type
                FROM information_schema.columns
                WHERE table_schema = 'public'
                  AND data_type = 'timestamp without time zone'
                ORDER BY table_name, column_name
            SQL;

        $result = $this->connection->executeQuery($sql);

        return $this->databasePlatformDetector->fetchAllAssociative($result);
    }

    private function createTimestampWithoutTimezoneIssue(array $tables): DatabaseConfigIssue
    {
        $tableList         = array_slice($tables, 0, 5);
        $tableDescriptions = array_map(
            fn (array $table): string => sprintf('%s.%s', $table['table_name'], $table['column_name']),
            $tableList,
        );
        $tableStr = implode(', ', $tableDescriptions);

        if (count($tables) > 5) {
            $tableStr .= sprintf(' (and %d more)', count($tables) - 5);
        }

        $fixCommands = [];

        foreach (array_slice($tables, 0, 5) as $table) {
            $fixCommands[] = sprintf(
                "-- Convert %s.%s to TIMESTAMPTZ
ALTER TABLE %s ALTER COLUMN %s TYPE timestamp with time zone;",
                $table['table_name'],
                $table['column_name'],
                $table['table_name'],
                $table['column_name'],
            );
        }

        return new DatabaseConfigIssue([
            'title'       => sprintf('%d columns using TIMESTAMP without timezone (CRITICAL)', count($tables)),
            'description' => sprintf(
                'Found %d columns using "timestamp without time zone": %s. ' . "

" .
                'TIMESTAMP WITHOUT TIME ZONE stores values without timezone info, causing bugs:' . "
" .
                '- Values are stored as-is (no UTC conversion)' . "
" .
                '- PHP DateTime conversions fail or produce wrong times' . "
" .
                '- Daylight saving time changes break data' . "
" .
                '- Moving servers across timezones corrupts timestamps' . "

" .
                'ALWAYS use TIMESTAMP WITH TIME ZONE (TIMESTAMPTZ) which:' . "
" .
                '- Stores in UTC internally' . "
" .
                '- Converts to session timezone on retrieval' . "
" .
                '- Works correctly with PHP DateTime' . "

" .
                'Note: Doctrine uses TIMESTAMP (without tz) by default - you must override!',
                count($tables),
                $tableStr,
            ),
            'severity'   => 'critical',
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'TIMESTAMP columns',
                currentValue: 'timestamp without time zone',
                recommendedValue: 'timestamp with time zone (TIMESTAMPTZ)',
                description: 'Convert to TIMESTAMPTZ for proper timezone handling',
                fixCommand: implode("

", $fixCommands) . "

-- In Doctrine, use:
#[Column(type: 'datetimetz')]",
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function getTimezoneFixCommand(string $timezone): string
    {
        return <<<SQL
            -- Option 1: In postgresql.conf (recommended)
            timezone = '{$timezone}'
            # Then restart PostgreSQL

            -- Option 2: Set for specific database
            ALTER DATABASE your_db SET timezone = '{$timezone}';

            -- Option 3: Set in session (temporary)
            SET TIME ZONE '{$timezone}';

            -- Option 4: In Doctrine DBAL configuration
            doctrine:
                dbal:
                    options:
                        timezone: '{$timezone}'
            SQL;
    }

    private function getMaxConnections(): int
    {
        $result = $this->connection->executeQuery('SHOW max_connections');
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return (int) ($row['max_connections'] ?? 100);
    }

    private function getCurrentConnections(): int
    {
        $sql    = 'SELECT count(*) as count FROM pg_stat_activity WHERE datname = current_database()';
        $result = $this->connection->executeQuery($sql);
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return (int) ($row['count'] ?? 0);
    }

    private function getIdleInTransactionSessionTimeout(): int
    {
        $result = $this->connection->executeQuery('SHOW idle_in_transaction_session_timeout');
        $row    = $this->databasePlatformDetector->fetchAssociative($result);
        $value  = $row['idle_in_transaction_session_timeout'] ?? '0';

        // Parse PostgreSQL time format (e.g., "5min", "300s", "0")
        if ('0' === $value || '0ms' === $value) {
            return 0;
        }

        // Convert to milliseconds
        if (str_ends_with((string) $value, 'ms')) {
            return (int) rtrim((string) $value, 'ms');
        }

        if (str_ends_with((string) $value, 's')) {
            return (int) rtrim((string) $value, 's') * 1000;
        }

        if (str_ends_with((string) $value, 'min')) {
            return (int) rtrim((string) $value, 'min') * 60 * 1000;
        }

        return 0;
    }

    private function getStatementTimeout(): int
    {
        $result = $this->connection->executeQuery('SHOW statement_timeout');
        $row    = $this->databasePlatformDetector->fetchAssociative($result);
        $value  = $row['statement_timeout'] ?? '0';

        if ('0' === $value || '0ms' === $value) {
            return 0;
        }

        if (str_ends_with((string) $value, 'ms')) {
            return (int) rtrim((string) $value, 'ms');
        }

        if (str_ends_with((string) $value, 's')) {
            return (int) rtrim((string) $value, 's') * 1000;
        }

        if (str_ends_with((string) $value, 'min')) {
            return (int) rtrim((string) $value, 'min') * 60 * 1000;
        }

        return 0;
    }

    /**
     * @return array<mixed>
     */
    private function getIdleConnections(): array
    {
        $sql = <<<SQL
                SELECT pid, usename, application_name, state, state_change
                FROM pg_stat_activity
                WHERE datname = current_database()
                  AND state = 'idle'
                  AND state_change < NOW() - INTERVAL '5 minutes'
            SQL;

        $result = $this->connection->executeQuery($sql);

        return $this->databasePlatformDetector->fetchAllAssociative($result);
    }

    private function createIdleConnectionsIssue(array $idleConnections): DatabaseConfigIssue
    {
        return new DatabaseConfigIssue([
            'title'       => sprintf('%d connections idle for >5 minutes', count($idleConnections)),
            'description' => sprintf(
                'Found %d connections that have been idle for more than 5 minutes. ' .
                'Idle connections consume resources and may indicate connection pooling issues. ' .
                'Consider using pgbouncer to manage connection lifecycle.',
                count($idleConnections),
            ),
            'severity'   => 'info',
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'Idle connections',
                currentValue: sprintf('%d idle connections', count($idleConnections)),
                recommendedValue: 'Use connection pooling',
                description: 'Implement pgbouncer to reduce idle connections',
                fixCommand: $this->getPgbouncerRecommendation(),
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function getMaxConnectionsFixCommand(int $recommended): string
    {
        return <<<CONFIG
            # In postgresql.conf:
            max_connections = {$recommended}

            # Note: PostgreSQL uses ~10MB RAM per connection
            # Recommended: Use pgbouncer for connection pooling instead of increasing max_connections

            # Restart PostgreSQL:
            sudo systemctl restart postgresql
            CONFIG;
    }

    private function getIdleInTransactionTimeoutFixCommand(): string
    {
        return <<<CONFIG
            # In postgresql.conf:
            idle_in_transaction_session_timeout = 300000  # 5 minutes

            # Or set for specific database:
            ALTER DATABASE your_db SET idle_in_transaction_session_timeout = 300000;

            # Or set globally:
            ALTER SYSTEM SET idle_in_transaction_session_timeout = 300000;
            SELECT pg_reload_conf();
            CONFIG;
    }

    private function getStatementTimeoutFixCommand(): string
    {
        return <<<CONFIG
            # In postgresql.conf:
            statement_timeout = 30000  # 30 seconds

            # Or set for specific database:
            ALTER DATABASE your_db SET statement_timeout = 30000;

            # Or set globally:
            ALTER SYSTEM SET statement_timeout = 30000;
            SELECT pg_reload_conf();

            # Adjust based on your application needs (web apps: 10-30s, batch jobs: higher)
            CONFIG;
    }

    private function getPgbouncerRecommendation(): string
    {
        return <<<PGBOUNCER
            # Install pgbouncer (connection pooler)
            # Ubuntu/Debian: sudo apt install pgbouncer

            # /etc/pgbouncer/pgbouncer.ini:
            [databases]
            your_db = host=localhost port=5432 dbname=your_db

            [pgbouncer]
            listen_port = 6432
            listen_addr = localhost
            auth_type = md5
            auth_file = /etc/pgbouncer/userlist.txt
            pool_mode = transaction
            max_client_conn = 1000
            default_pool_size = 25
            min_pool_size = 5
            reserve_pool_size = 5
            reserve_pool_timeout = 3
            max_db_connections = 100
            max_user_connections = 100

            # Then connect to PostgreSQL via pgbouncer (port 6432 instead of 5432)
            # Connection string: postgresql://user:pass@localhost:6432/your_db
            PGBOUNCER;
    }

    private function getStandardConformingStrings(): string
    {
        $result = $this->connection->executeQuery('SHOW standard_conforming_strings');
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['standard_conforming_strings'] ?? 'on';
    }

    private function getCheckFunctionBodies(): string
    {
        $result = $this->connection->executeQuery('SHOW check_function_bodies');
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['check_function_bodies'] ?? 'on';
    }
}
