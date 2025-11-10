<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Analyzes table engine configuration.
 * Detects tables using MyISAM instead of InnoDB (no transactions, no foreign keys).
 * Platform compatibility:
 * - MySQL: Full support - checks for InnoDB vs MyISAM
 * - MariaDB: Full support - checks for InnoDB vs MyISAM/Aria
 * - PostgreSQL: ⏭️ Skipped (doesn't have storage engines)
 * - Doctrine DBAL: 2.x and 3.x+ compatible
 */
class InnoDBEngineAnalyzer implements AnalyzerInterface
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
        private ?DatabasePlatformDetector $databasePlatformDetector = null,
        /**
         * @readonly
         */
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param QueryDataCollection $queryDataCollection - Not used, config analyzers run independently
     */
    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                try {
                    $detector = $this->databasePlatformDetector ?? new DatabasePlatformDetector($this->connection);

                    // Skip if not MySQL/MariaDB (PostgreSQL doesn't have storage engines)
                    if (!$detector->isMySQLFamily()) {
                        return;
                    }

                    $nonInnoDBTables = $this->getNonInnoDBTables($detector);

                    if ([] !== $nonInnoDBTables) {
                        $tableList = implode(', ', array_slice(array_column($nonInnoDBTables, 'name'), 0, 5));

                        if (count($nonInnoDBTables) > 5) {
                            $tableList .= sprintf(' (and %d more)', count($nonInnoDBTables) - 5);
                        }

                        $engineList = array_unique(array_column($nonInnoDBTables, 'engine'));

                        yield new DatabaseConfigIssue([
                            'title'       => sprintf('%d tables not using InnoDB engine', count($nonInnoDBTables)),
                            'description' => sprintf(
                                'Found %d tables using non-InnoDB engines (%s): %s. ' .
                                'MyISAM lacks transaction support, foreign key constraints, and crash recovery. ' .
                                'InnoDB is recommended for most use cases.',
                                count($nonInnoDBTables),
                                implode(', ', $engineList),
                                $tableList,
                            ),
                            'severity'   => count($nonInnoDBTables) > 5 ? 'critical' : 'warning',
                            'suggestion' => $this->suggestionFactory->createConfiguration(
                                setting: 'Table engine',
                                currentValue: implode(', ', $engineList),
                                recommendedValue: 'InnoDB',
                                description: 'InnoDB provides ACID transactions, foreign keys, and better crash recovery',
                                fixCommand: $this->getConversionCommand($nonInnoDBTables),
                            ),
                            'backtrace' => null,
                            'queries'   => [],
                        ]);
                    }
                } catch (\Throwable $throwable) {
                    $this->logger?->error('InnoDBEngineAnalyzer failed', [
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                        'file' => $throwable->getFile(),
                        'line' => $throwable->getLine(),
                    ]);
                }
            },
        );
    }

    private function getNonInnoDBTables(DatabasePlatformDetector $databasePlatformDetector): array
    {
        $databaseName = $this->connection->getDatabase();
        $result       = $this->connection->executeQuery(
            "SELECT TABLE_NAME, ENGINE
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ?
             AND ENGINE != 'InnoDB'
             AND TABLE_TYPE = 'BASE TABLE'",
            [$databaseName],
        );

        // DBAL 2.x/3.x compatibility
        $tables = $databasePlatformDetector->fetchAllAssociative($result);

        return array_map(fn (array $row): array => [
            'name'   => $row['TABLE_NAME'],
            'engine' => $row['ENGINE'] ?? 'Unknown',
        ], $tables);
    }

    private function getConversionCommand(array $tables): string
    {
        $commands   = [];
        $commands[] = '-- WARNING: Converting to InnoDB may take time on large tables';
        $commands[] = "-- Backup your database before running these commands!
";

        foreach (array_slice($tables, 0, 10) as $table) {
            $commands[] = sprintf('ALTER TABLE `%s` ENGINE=InnoDB;', $table['name']);
        }

        if (count($tables) > 10) {
            $commands[] = "
-- ... and " . (count($tables) - 10) . ' more tables';
        }

        return implode("
", $commands);
    }
}
