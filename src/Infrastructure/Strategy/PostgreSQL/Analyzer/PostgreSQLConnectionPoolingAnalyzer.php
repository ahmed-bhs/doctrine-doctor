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
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\ConnectionPoolingAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use Doctrine\DBAL\Connection;

/**
 * PostgreSQL-specific connection pooling analyzer.
 * Detects issues with max_connections, timeouts, and idle connections.
 */
final class PostgreSQLConnectionPoolingAnalyzer implements ConnectionPoolingAnalyzerInterface
{
    private const RECOMMENDED_MIN_CONNECTIONS = 100;

    public function __construct(
        private readonly Connection $connection,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly DatabasePlatformDetector $databasePlatformDetector,
    ) {
    }

    public function analyze(): iterable
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
                    '- Table locks that block other queries' . "\n" .
                    '- VACUUM blocked (table bloat)' . "\n" .
                    '- Connection pool exhaustion' . "\n" .
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

    /**
     * @param array<mixed> $idleConnections
     */
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
}
