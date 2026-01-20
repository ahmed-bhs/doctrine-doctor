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
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\PerformanceConfigAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\DBAL\Connection;

/**
 * MySQL-specific performance configuration analyzer.
 * Detects suboptimal performance settings like query cache, InnoDB settings, binary logs, buffer pool.
 */
final class MySQLPerformanceConfigAnalyzer implements PerformanceConfigAnalyzerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly DatabasePlatformDetector $databasePlatformDetector,
    ) {
    }

    public function analyze(): iterable
    {
        // Check 1: Query Cache (deprecated in MySQL 5.7.20+, removed in 8.0+)
        $queryCacheType = $this->getQueryCacheType();

        if ('OFF' !== $queryCacheType) {
            yield new DatabaseConfigIssue([
                'title'       => 'Query Cache enabled (deprecated)',
                'description' => sprintf(
                    'Query cache is enabled (query_cache_type = %s). ' .
                    'This feature is DEPRECATED since MySQL 5.7.20 and REMOVED in MySQL 8.0+. ' .
                    'Query cache causes severe performance problems:' . "\n" .
                    '- Global mutex lock contention' . "\n" .
                    '- Cache invalidation on ANY table write' . "\n" .
                    '- Modern systems have enough RAM for InnoDB buffer pool' . "\n" .
                    'Disable query_cache immediately for better performance.',
                    $queryCacheType,
                ),
                'severity' => Severity::critical(),
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'query_cache_type',
                    currentValue: $queryCacheType,
                    recommendedValue: 'OFF',
                    description: 'Disable deprecated query cache for better performance',
                    fixCommand: $this->getQueryCacheFixCommand(),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }

        // Check 2: InnoDB Flush Log (full ACID = slow in development)
        $flushLog = $this->getInnoDBFlushLog();

        if (1 === $flushLog) {
            yield new DatabaseConfigIssue([
                'title'       => 'InnoDB full ACID durability in development',
                'description' => 'innodb_flush_log_at_trx_commit is set to 1 (full ACID durability). ' .
                    'This flushes logs to disk on EVERY transaction commit, which is very slow. ' .
                    'In development, you can safely use value 2 for 10x faster writes. ' .
                    'Note: Keep value 1 in production for data safety.',
                'severity' => Severity::info(),
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'innodb_flush_log_at_trx_commit',
                    currentValue: '1 (full ACID)',
                    recommendedValue: '2 (for dev only)',
                    description: 'Set to 2 in development for faster performance',
                    fixCommand: $this->getInnoDBFlushLogFixCommand(),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }

        // Check 3: Binary Logs enabled (wastes disk space in development)
        if ($this->isBinaryLogEnabled()) {
            yield new DatabaseConfigIssue([
                'title'       => 'Binary logging enabled in development',
                'description' => 'Binary logging (binlog) is enabled. ' .
                    'Binary logs are used for replication and point-in-time recovery, but waste disk space in development. ' .
                    'Unless you are testing replication, disable binlog in dev environments.',
                'severity' => Severity::info(),
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'log_bin',
                    currentValue: 'ON',
                    recommendedValue: 'OFF (for dev)',
                    description: 'Disable binary logging in development to save disk space',
                    fixCommand: $this->getBinaryLogFixCommand(),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }

        // Check 4: InnoDB Buffer Pool Size too small
        $bufferPoolSize = $this->getInnoDBBufferPoolSize();

        if ($bufferPoolSize < 134217728) { // < 128MB
            $bufferPoolMB = (int) round($bufferPoolSize / 1024 / 1024);

            yield new DatabaseConfigIssue([
                'title'       => 'InnoDB buffer pool size too small',
                'description' => sprintf(
                    'innodb_buffer_pool_size is %dMB, which is very small. ' .
                    'The buffer pool caches table and index data in memory. ' .
                    'A small buffer pool causes excessive disk I/O and poor performance. ' .
                    'Recommended: 50-70%% of available RAM (minimum 128MB, ideally 512MB+ for dev).',
                    $bufferPoolMB,
                ),
                'severity'   => $bufferPoolSize < 67108864 ? Severity::warning() : Severity::info(), // < 64MB = warning
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'innodb_buffer_pool_size',
                    currentValue: sprintf('%dMB', $bufferPoolMB),
                    recommendedValue: '512MB (for dev) / 50-70% RAM (for prod)',
                    description: 'Increase buffer pool size for better performance',
                    fixCommand: $this->getBufferPoolSizeFixCommand(536870912), // 512MB
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }
    }

    private function getQueryCacheType(): string
    {
        try {
            $result = $this->connection->executeQuery("SHOW VARIABLES LIKE 'query_cache_type'");
            $row    = $this->databasePlatformDetector->fetchAssociative($result);

            return strtoupper($row['Value'] ?? 'OFF');
        } catch (\Throwable) {
            // query_cache_type doesn't exist in MySQL 8.0+
            return 'OFF';
        }
    }

    private function getInnoDBFlushLog(): int
    {
        $result = $this->connection->executeQuery("SHOW VARIABLES LIKE 'innodb_flush_log_at_trx_commit'");
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return (int) ($row['Value'] ?? 1);
    }

    private function isBinaryLogEnabled(): bool
    {
        try {
            $result = $this->connection->executeQuery("SHOW VARIABLES LIKE 'log_bin'");
            $row    = $this->databasePlatformDetector->fetchAssociative($result);

            return 'ON' === strtoupper($row['Value'] ?? 'OFF');
        } catch (\Throwable) {
            return false;
        }
    }

    private function getInnoDBBufferPoolSize(): int
    {
        $result = $this->connection->executeQuery("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'");
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return (int) ($row['Value'] ?? 134217728); // Default 128MB
    }

    private function getQueryCacheFixCommand(): string
    {
        return <<<SQL
            -- In MySQL configuration file (my.cnf or my.ini):
            [mysqld]
            query_cache_type = OFF
            query_cache_size = 0

            -- Or set globally (MySQL 5.7 only, removed in 8.0+):
            SET GLOBAL query_cache_type = OFF;
            SET GLOBAL query_cache_size = 0;

            -- Restart MySQL for changes to take effect
            SQL;
    }

    private function getInnoDBFlushLogFixCommand(): string
    {
        return <<<SQL
            -- DEVELOPMENT ONLY (NOT for production!)
            -- In MySQL configuration file (my.cnf or my.ini):
            [mysqld]
            innodb_flush_log_at_trx_commit = 2  # Dev only

            -- Or set globally:
            SET GLOBAL innodb_flush_log_at_trx_commit = 2;

            -- Values:
            -- 0 = flush every second (fastest, data loss on crash)
            -- 1 = flush on every commit (slowest, full ACID - use in PRODUCTION)
            -- 2 = flush on every commit to OS cache, write to disk every second (balanced for dev)

            -- IMPORTANT: Use value 1 in production for data safety!
            SQL;
    }

    private function getBinaryLogFixCommand(): string
    {
        return <<<SQL
            -- DEVELOPMENT ONLY (binlog needed for replication/backups in production)
            -- In MySQL configuration file (my.cnf or my.ini):
            [mysqld]
            # Comment out or remove the following line:
            # log_bin = mysql-bin
            skip-log-bin

            -- Or alternatively (MySQL 8.0.21+):
            disable_log_bin = 1

            -- Restart MySQL for changes to take effect

            -- Note: In production, keep binlog enabled for backups and replication!
            SQL;
    }

    private function getBufferPoolSizeFixCommand(int $recommendedBytes): string
    {
        $recommendedMB = $recommendedBytes / 1024 / 1024;

        return <<<SQL
            -- In MySQL configuration file (my.cnf or my.ini):
            [mysqld]
            innodb_buffer_pool_size = {$recommendedBytes}  # {$recommendedMB}MB

            -- Or set globally (requires restart):
            SET GLOBAL innodb_buffer_pool_size = {$recommendedBytes};

            -- Restart MySQL for changes to take effect

            -- Sizing guidelines:
            -- Development: 256MB - 512MB (minimum)
            -- Production: 50-70% of available RAM
            -- Example: 8GB RAM server -> set to 4-5GB (4294967296 - 5368709120 bytes)
            SQL;
    }
}
