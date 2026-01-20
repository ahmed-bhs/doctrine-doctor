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
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\CharsetAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\DBAL\Connection;

/**
 * PostgreSQL-specific encoding analyzer.
 * Detects issues with server/client encoding and template databases.
 */
final class PostgreSQLCharsetAnalyzer implements CharsetAnalyzerInterface
{
    private const RECOMMENDED_ENCODING = 'UTF8';

    private const PROBLEMATIC_ENCODINGS = ['SQL_ASCII', 'LATIN1', 'WIN1252'];

    public function __construct(
        private readonly Connection $connection,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly DatabasePlatformDetector $databasePlatformDetector,
    ) {
    }

    public function analyze(): iterable
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
            'severity'    => 'SQL_ASCII' === $encoding ? Severity::critical() : Severity::warning(),
            'suggestion'  => $this->suggestionFactory->createConfiguration(
                setting: 'Database encoding',
                currentValue: $encoding,
                recommendedValue: self::RECOMMENDED_ENCODING,
                description: 'Recreate database with UTF8 encoding',
                fixCommand: sprintf(
                    "-- Encoding cannot be changed after database creation.\n" .
                    "-- You must dump, drop, recreate, and restore:\n\n" .
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
                fixCommand: "-- In postgresql.conf or per-connection:\nSET client_encoding = '{$serverEncoding}';\n\n-- In Doctrine DBAL:\ndoctrine:\n    dbal:\n        options:\n            client_encoding: '{$serverEncoding}'",
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    /**
     * @param array<mixed> $templates
     */
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
                fixCommand: "-- Recreate template1 (requires superuser):\n" .
                    "UPDATE pg_database SET datistemplate = FALSE WHERE datname = 'template1';\n" .
                    "DROP DATABASE template1;\n" .
                    "CREATE DATABASE template1 WITH TEMPLATE = template0 ENCODING = 'UTF8' LC_COLLATE = 'en_US.UTF-8' LC_CTYPE = 'en_US.UTF-8';\n" .
                    "UPDATE pg_database SET datistemplate = TRUE WHERE datname = 'template1';",
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }
}
