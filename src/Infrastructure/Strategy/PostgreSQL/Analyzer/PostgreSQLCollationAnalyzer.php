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
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\CollationAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use Doctrine\DBAL\Connection;

/**
 * PostgreSQL-specific collation analyzer.
 * Detects issues with collation configuration ("C" vs locale-aware, ICU vs libc).
 */
final class PostgreSQLCollationAnalyzer implements CollationAnalyzerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly DatabasePlatformDetector $databasePlatformDetector,
    ) {
    }

    public function analyze(): iterable
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
                    "-- Collation cannot be changed after creation. Dump and recreate:\n\n" .
                    "pg_dump -U user %s > backup.sql\n" .
                    "DROP DATABASE %s;\n" .
                    "CREATE DATABASE %s ENCODING 'UTF8' LC_COLLATE='en_US.UTF-8' LC_CTYPE='en_US.UTF-8';\n" .
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
                fixCommand: "-- For future databases:\nCREATE DATABASE newdb ENCODING 'UTF8' LC_COLLATE='en_US.UTF-8' LC_CTYPE='en_US.UTF-8';",
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

    /**
     * @param array<mixed> $mismatches
     */
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
        $descriptionStr = implode("\n- ", $descriptions);

        if (count($mismatches) > 3) {
            $descriptionStr .= sprintf("\n- ... and %d more", count($mismatches) - 3);
        }

        return new DatabaseConfigIssue([
            'title'       => sprintf('%d foreign key collation mismatches detected', count($mismatches)),
            'description' => sprintf(
                'Found %d foreign key relationships where child and parent columns have different collations. ' .
                'This can cause performance issues and query failures:' . "\n- " . $descriptionStr,
                count($mismatches),
            ),
            'severity'   => 'warning',
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'Foreign key collations',
                currentValue: 'Mismatched collations',
                recommendedValue: 'Matching collations',
                description: 'Alter columns to use matching collations',
                fixCommand: "-- Example fix (adjust data type as needed):\nALTER TABLE child_table ALTER COLUMN child_column TYPE varchar(255) COLLATE \"en_US.UTF-8\";",
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
                'ICU collations provide: ' . "\n" .
                '- Better Unicode support' . "\n" .
                '- Consistent collation across platforms' . "\n" .
                '- Deterministic versions (no breaking changes)' . "\n" .
                'Consider using ICU for new databases.',
            'severity'   => 'info',
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'Collation provider',
                currentValue: 'libc',
                recommendedValue: 'ICU',
                description: 'Use ICU collations for new databases',
                fixCommand: "-- For new databases (PostgreSQL 15+):\nCREATE DATABASE newdb LOCALE_PROVIDER = icu ICU_LOCALE = 'en-US';",
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }
}
