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
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\ConnectionPoolingAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\DBAL\Connection;

/**
 * MySQL-specific connection pooling analyzer.
 * Detects issues with max_connections and connection pool utilization.
 */
final class MySQLConnectionPoolingAnalyzer implements ConnectionPoolingAnalyzerInterface
{
    private const RECOMMENDED_MIN_CONNECTIONS = 100;

    private const RECOMMENDED_MAX_CONNECTIONS = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly DatabasePlatformDetector $databasePlatformDetector,
    ) {
    }

    public function analyze(): iterable
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
                'severity' => Severity::warning(),
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
                'severity'   => $utilizationPercent > 90 ? Severity::critical() : Severity::warning(),
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

    private function getMaxConnectionsFixCommand(int $recommended): string
    {
        return "-- In your MySQL configuration file (my.cnf or my.ini):\n" .
               "[mysqld]\n" .
               "max_connections = {$recommended}\n\n" .
               "-- Or set it globally (requires SUPER privilege and restart):\n" .
               "SET GLOBAL max_connections = {$recommended};\n\n" .
               '-- Note: Restart MySQL for persistent changes';
    }
}
