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
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\CharsetAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\DBAL\Connection;

/**
 * MySQL-specific charset analyzer.
 * Detects issues with utf8 vs utf8mb4 charset configuration.
 */
final readonly class MySQLCharsetAnalyzer implements CharsetAnalyzerInterface
{
    private const string RECOMMENDED_CHARSET = 'utf8mb4';

    /**
     * System/framework tables that can be ignored for charset issues.
     */
    private const array SYSTEM_TABLES = [
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
        private Connection $connection,
        private SuggestionFactory $suggestionFactory,
        private DatabasePlatformDetector $databasePlatformDetector,
    ) {
    }

    public function analyze(): iterable
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
                'severity' => Severity::warning(),
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
                'severity'   => count($problematicTables) > 10 ? Severity::critical() : Severity::warning(),
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

    private function getDatabaseCharset(string $databaseName): string
    {
        $result = $this->connection->executeQuery(
            'SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$databaseName],
        );

        $row = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['DEFAULT_CHARACTER_SET_NAME'] ?? 'unknown';
    }

    /**
     * @return array<string>
     */
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
        return array_filter($tableNames, fn (string $tableName): bool => array_all(self::SYSTEM_TABLES, fn ($systemTable) => !str_contains(strtolower($tableName), strtolower((string) $systemTable))));
    }

    /**
     * @param array<string> $tables
     */
    private function getTableConversionCommand(array $tables): string
    {
        $commands = [];

        foreach (array_slice($tables, 0, 10) as $table) {
            $commands[] = sprintf('ALTER TABLE `%s` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;', $table);
        }

        if (count($tables) > 10) {
            $commands[] = '-- ... and ' . (count($tables) - 10) . ' more tables';
        }

        return implode("\n", $commands);
    }
}
