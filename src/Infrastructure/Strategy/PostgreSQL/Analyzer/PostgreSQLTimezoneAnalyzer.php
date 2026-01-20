<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\PostgreSQL\Analyzer;

use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\TimezoneAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/**
 * PostgreSQL-specific timezone analyzer.
 * Detects timezone mismatches and TIMESTAMP without timezone usage.
 */
final class PostgreSQLTimezoneAnalyzer implements TimezoneAnalyzerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly DatabasePlatformDetector $databasePlatformDetector,
    ) {
    }

    public function analyze(): iterable
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
                'This mismatch causes subtle bugs:' . "\n" .
                '- DateTime values converted incorrectly' . "\n" .
                '- NOW(), CURRENT_TIMESTAMP return different times than PHP' . "\n" .
                '- Date comparisons fail' . "\n" .
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

    /**
     * @param array<mixed> $tables
     */
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
                "-- Convert %s.%s to TIMESTAMPTZ\nALTER TABLE %s ALTER COLUMN %s TYPE timestamp with time zone;",
                $table['table_name'],
                $table['column_name'],
                $table['table_name'],
                $table['column_name'],
            );
        }

        return new DatabaseConfigIssue([
            'title'       => sprintf('%d columns using TIMESTAMP without timezone (CRITICAL)', count($tables)),
            'description' => sprintf(
                'Found %d columns using "timestamp without time zone": %s. ' . "\n\n" .
                'TIMESTAMP WITHOUT TIME ZONE stores values without timezone info, causing bugs:' . "\n" .
                '- Values are stored as-is (no UTC conversion)' . "\n" .
                '- PHP DateTime conversions fail or produce wrong times' . "\n" .
                '- Daylight saving time changes break data' . "\n" .
                '- Moving servers across timezones corrupts timestamps' . "\n\n" .
                'ALWAYS use TIMESTAMP WITH TIME ZONE (TIMESTAMPTZ) which:' . "\n" .
                '- Stores in UTC internally' . "\n" .
                '- Converts to session timezone on retrieval' . "\n" .
                '- Works correctly with PHP DateTime' . "\n\n" .
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
                fixCommand: implode("\n\n", $fixCommands) . "\n\n-- In Doctrine, use:\n#[Column(type: 'datetimetz')]",
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
}
