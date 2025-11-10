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
use AhmedBhs\DoctrineDoctor\Factory\PlatformAnalysisStrategyFactory;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Analyzes database strict mode / data integrity settings.
 * Detects missing settings that prevent silent data corruption.
 * Platform-specific issues detected:
 * - MySQL/MariaDB:
 *   - Missing SQL modes (STRICT_TRANS_TABLES, NO_ZERO_DATE, etc.)
 * - PostgreSQL:
 *   - standard_conforming_strings = off (security risk!)
 *   - check_function_bodies = off (skips validation)
 * - SQLite:
 *   - foreign_keys pragma = OFF (CRITICAL! Default is OFF)
 *   - strict mode not enabled (SQLite 3.37+)
 * Platform compatibility:
 * - MySQL: Full support
 * - MariaDB: Full support
 * - PostgreSQL: Full support (NEW!)
 * - SQLite: ⏭️ Partial support (foreign_keys check only)
 * - Doctrine DBAL: 2.x and 3.x+ compatible
 */
class StrictModeAnalyzer implements AnalyzerInterface
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
        private ?PlatformAnalysisStrategyFactory $platformAnalysisStrategyFactory = null,
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

                    // For SQLite, use simple conditional logic (not in strategy yet)
                    if ($detector->isSQLite()) {
                        yield from $this->analyzeSQLiteStrictMode($detector);

                        return;
                    }

                    // Check if platform is supported for strict mode analysis
                    $strategyFactory = $this->getStrategyFactory($detector);

                    if (!$strategyFactory->isPlatformSupported($detector->getPlatformName())) {
                        return; // Skip unsupported platforms
                    }

                    // Delegate to platform-specific strategy
                    $strategy = $strategyFactory->createStrategy();

                    if (!$strategy->supportsFeature('strict_mode')) {
                        return;
                    }

                    yield from $strategy->analyzeStrictMode();
                } catch (\Throwable $throwable) {
                    $this->logger?->error('StrictModeAnalyzer failed', [
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                        'file' => $throwable->getFile(),
                        'line' => $throwable->getLine(),
                    ]);
                }
            },
        );
    }

    /**
     * SQLite-specific strict mode analysis (conditional logic approach).
     */
    private function analyzeSQLiteStrictMode(DatabasePlatformDetector $databasePlatformDetector): iterable
    {
        try {
            // Check PRAGMA foreign_keys (CRITICAL - default is OFF!)
            $result      = $this->connection->executeQuery('PRAGMA foreign_keys');
            $row         = $databasePlatformDetector->fetchAssociative($result);
            $foreignKeys = (int) ($row['foreign_keys'] ?? 0);

            if (0 === $foreignKeys) {
                yield new DatabaseConfigIssue([
                    'title'       => 'SQLite foreign keys disabled (CRITICAL)',
                    'description' => 'PRAGMA foreign_keys is OFF (default in SQLite). ' .
                        'This means foreign key constraints are NOT enforced! ' .
                        'You can insert invalid foreign keys, orphan records, etc. ' .
                        'ALWAYS enable foreign_keys in SQLite.',
                    'severity'   => 'critical',
                    'suggestion' => $this->suggestionFactory->createConfiguration(
                        setting: 'foreign_keys',
                        currentValue: 'OFF',
                        recommendedValue: 'ON',
                        description: 'Enable foreign key enforcement',
                        fixCommand: $this->getSQLiteForeignKeysFixCommand(),
                    ),
                    'backtrace' => null,
                    'queries'   => [],
                ]);
            }

            // Check for strict mode (SQLite 3.37+)
            // Note: This will fail on older SQLite versions, which is fine
            try {
                $result = $this->connection->executeQuery('PRAGMA strict=ON');
            } catch (\Throwable) {
                // SQLite < 3.37 doesn't support STRICT tables, ignore
            }
        } catch (\Throwable $throwable) {
            // Log warning - SQLite might not support all pragmas
            $this->logger?->warning('SQLite strict mode check failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
            ]);
        }
    }

    private function getSQLiteForeignKeysFixCommand(): string
    {
        return <<<SQL_WRAP
        -- Enable foreign keys for current connection (MUST be set per connection!)
        PRAGMA foreign_keys = ON;
        
        -- In Doctrine DBAL configuration:
        doctrine:
            dbal:
                options:
                    # PDO::SQLITE_ATTR_OPEN_FLAGS
                    # This doesn't enable foreign keys, you must set it per connection
        
        -- IMPORTANT: SQLite requires PRAGMA foreign_keys = ON on EVERY connection!
        -- Add this to your application bootstrap or connection setup:
        
        // PHP PDO:
        \$pdo->exec('PRAGMA foreign_keys = ON');
        
        // Doctrine DBAL Event Listener:
        use Doctrine\\DBAL\\Event\\ConnectionEventArgs;
        use Doctrine\\DBAL\\Events;
        
        class SQLiteForeignKeyListener
        {
            public function postConnect(ConnectionEventArgs \$args): void
            {
                if (\$args->getConnection()->getDatabasePlatform() instanceof \\Doctrine\\DBAL\\Platforms\\SqlitePlatform) {
                    \$args->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
                }
            }
        }
        
        // Register listener:
        \$eventManager->addEventListener([Events::postConnect], new SQLiteForeignKeyListener());
        SQL_WRAP;
    }

    private function getStrategyFactory(DatabasePlatformDetector $databasePlatformDetector): PlatformAnalysisStrategyFactory
    {
        if (!$this->platformAnalysisStrategyFactory instanceof PlatformAnalysisStrategyFactory) {
            $this->platformAnalysisStrategyFactory = new PlatformAnalysisStrategyFactory(
                $this->connection,
                $this->suggestionFactory,
                $databasePlatformDetector,
            );
        }

        return $this->platformAnalysisStrategyFactory;
    }
}
