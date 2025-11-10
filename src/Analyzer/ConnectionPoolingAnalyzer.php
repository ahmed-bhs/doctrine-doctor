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
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Analyzes database connection pool configuration.
 * Checks max_connections and recommends optimal settings.
 * Platform-specific issues detected:
 * - MySQL/MariaDB:
 *   - Low max_connections setting
 *   - High connection pool utilization
 *   - High wait_timeout
 * - PostgreSQL:
 *   - Low max_connections (uses more RAM than MySQL)
 *   - idle_in_transaction_session_timeout = 0 (CRITICAL!)
 *   - No statement_timeout
 *   - Recommendation for pgbouncer
 * Platform compatibility:
 * - MySQL: Full support
 * - MariaDB: Full support
 * - PostgreSQL: Full support (NEW!)
 * - SQLite: ⏭️ Skipped (embedded database)
 * - Doctrine DBAL: 2.x and 3.x+ compatible
 */
class ConnectionPoolingAnalyzer implements AnalyzerInterface
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

                    // Check if platform is supported for connection pooling analysis
                    $strategyFactory = $this->getStrategyFactory($detector);

                    if (!$strategyFactory->isPlatformSupported($detector->getPlatformName())) {
                        return; // Skip unsupported platforms (SQLite, etc.)
                    }

                    // Delegate to platform-specific strategy
                    $strategy = $strategyFactory->createStrategy();

                    if (!$strategy->supportsFeature('pooling')) {
                        return;
                    }

                    yield from $strategy->analyzeConnectionPooling();
                } catch (\Throwable $throwable) {
                    $this->logger?->error('ConnectionPoolingAnalyzer failed', [
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                        'file' => $throwable->getFile(),
                        'line' => $throwable->getLine(),
                    ]);
                }
            },
        );
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
