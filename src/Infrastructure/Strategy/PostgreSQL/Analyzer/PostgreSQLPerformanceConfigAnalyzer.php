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
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\PerformanceConfigAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use Doctrine\DBAL\Connection;

/**
 * PostgreSQL-specific performance configuration analyzer.
 * Detects issues with shared_buffers, work_mem, and synchronous_commit.
 */
final class PostgreSQLPerformanceConfigAnalyzer implements PerformanceConfigAnalyzerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly DatabasePlatformDetector $databasePlatformDetector,
    ) {
    }

    public function analyze(): iterable
    {
        // Check 1: shared_buffers too small
        $sharedBuffers = $this->getSharedBuffers();

        if ($sharedBuffers < 134217728) { // < 128MB
            $sharedBuffersMB = (int) round($sharedBuffers / 1024 / 1024);

            yield new DatabaseConfigIssue([
                'title'       => 'shared_buffers too small',
                'description' => sprintf(
                    'shared_buffers is %dMB, which is very small. ' .
                    'shared_buffers caches table and index data in PostgreSQL shared memory. ' .
                    'A small buffer causes excessive disk I/O and poor performance. ' .
                    'Recommended: 25%% of available RAM (minimum 128MB, ideally 256MB+ for dev).',
                    $sharedBuffersMB,
                ),
                'severity'   => $sharedBuffers < 67108864 ? 'warning' : 'info', // < 64MB = warning
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'shared_buffers',
                    currentValue: sprintf('%dMB', $sharedBuffersMB),
                    recommendedValue: '256MB (for dev) / 25% RAM (for prod)',
                    description: 'Increase shared_buffers for better performance',
                    fixCommand: $this->getSharedBuffersFixCommand(268435456), // 256MB
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }

        // Check 2: work_mem too small
        $workMem = $this->getWorkMem();

        if ($workMem < 4194304) { // < 4MB
            $workMemMB = round($workMem / 1024 / 1024, 1);

            yield new DatabaseConfigIssue([
                'title'       => 'work_mem too small',
                'description' => sprintf(
                    'work_mem is %.1fMB, which is very small. ' .
                    'work_mem controls memory used for sorts, hashes, and joins. ' .
                    'Low work_mem causes disk-based operations (temp files) which are 10-100x slower. ' .
                    'Recommended: 4-16MB for web apps, 32-256MB for analytics.',
                    $workMemMB,
                ),
                'severity'   => $workMem < 2097152 ? 'warning' : 'info', // < 2MB = warning
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'work_mem',
                    currentValue: sprintf('%.1fMB', $workMemMB),
                    recommendedValue: '8MB (for web apps)',
                    description: 'Increase work_mem to reduce temp file usage',
                    fixCommand: $this->getWorkMemFixCommand(8388608), // 8MB
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }

        // Check 3: synchronous_commit = on in development (suggestion to disable for dev)
        $synchronousCommit = $this->getSynchronousCommit();

        if ('on' === $synchronousCommit) {
            yield new DatabaseConfigIssue([
                'title'       => 'synchronous commit enabled in development',
                'description' => 'synchronous_commit is ON (full durability). ' .
                    'This waits for WAL writes to disk on every commit, which is slow. ' .
                    'In development, you can safely use "off" for 10x faster writes. ' .
                    'Note: Keep "on" in production for data safety.',
                'severity'   => 'info',
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'synchronous_commit',
                    currentValue: 'on (full durability)',
                    recommendedValue: 'off (for dev only)',
                    description: 'Disable synchronous_commit in development for faster writes',
                    fixCommand: $this->getSynchronousCommitFixCommand(),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }
    }

    private function getSharedBuffers(): int
    {
        $result = $this->connection->executeQuery('SHOW shared_buffers');
        $row    = $this->databasePlatformDetector->fetchAssociative($result);
        $value  = $row['shared_buffers'] ?? '128MB';

        // Parse PostgreSQL size format (e.g., "128MB", "1GB", "8192kB")
        return $this->parsePostgreSQLSize($value);
    }

    private function getWorkMem(): int
    {
        $result = $this->connection->executeQuery('SHOW work_mem');
        $row    = $this->databasePlatformDetector->fetchAssociative($result);
        $value  = $row['work_mem'] ?? '4MB';

        return $this->parsePostgreSQLSize($value);
    }

    private function getSynchronousCommit(): string
    {
        $result = $this->connection->executeQuery('SHOW synchronous_commit');
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return strtolower($row['synchronous_commit'] ?? 'on');
    }

    private function parsePostgreSQLSize(string $value): int
    {
        // Convert PostgreSQL size format to bytes
        $value = trim($value);

        if (is_numeric($value)) {
            return (int) $value; // Already in bytes
        }

        $units = [
            'kB' => 1024,
            'MB' => 1024 * 1024,
            'GB' => 1024 * 1024 * 1024,
            'TB' => 1024 * 1024 * 1024 * 1024,
        ];

        foreach ($units as $unit => $multiplier) {
            if (str_ends_with($value, $unit)) {
                $number = (int) rtrim($value, $unit);

                return $number * $multiplier;
            }
        }

        return (int) $value;
    }

    private function getSharedBuffersFixCommand(int $recommendedBytes): string
    {
        $recommendedMB = (int) ($recommendedBytes / 1024 / 1024);

        return <<<CONFIG
            # In postgresql.conf:
            shared_buffers = {$recommendedMB}MB

            # Restart PostgreSQL:
            sudo systemctl restart postgresql

            # Sizing guidelines:
            # Development: 256MB - 512MB (minimum)
            # Production: 25% of available RAM
            # Example: 8GB RAM server -> set to 2GB (2048MB)

            # Note: Changes to shared_buffers require PostgreSQL restart!
            CONFIG;
    }

    private function getWorkMemFixCommand(int $recommendedBytes): string
    {
        $recommendedMB = (int) ($recommendedBytes / 1024 / 1024);

        return <<<CONFIG
            # In postgresql.conf:
            work_mem = {$recommendedMB}MB

            # Or set for specific database:
            ALTER DATABASE your_db SET work_mem = '{$recommendedMB}MB';

            # Or set globally:
            ALTER SYSTEM SET work_mem = '{$recommendedMB}MB';
            SELECT pg_reload_conf();

            # Sizing guidelines:
            # work_mem is PER OPERATION (per sort/hash)
            # Multiple concurrent connections can use multiple work_mem allocations
            # Formula: work_mem = (Total RAM * 0.25) / max_connections
            # Web apps: 4-16MB typically works well
            # Analytics: 32-256MB for complex queries
            CONFIG;
    }

    private function getSynchronousCommitFixCommand(): string
    {
        return <<<CONFIG
            # DEVELOPMENT ONLY (NOT for production!)
            # In postgresql.conf:
            synchronous_commit = off  # Dev only

            # Or set for specific database:
            ALTER DATABASE your_db SET synchronous_commit = off;

            # Or set globally:
            ALTER SYSTEM SET synchronous_commit = off;
            SELECT pg_reload_conf();

            # Values:
            # on  = wait for WAL write to disk (full durability - use in PRODUCTION)
            # off = don't wait for WAL write (faster, minimal data loss risk on crash)

            # IMPORTANT: Use 'on' in production for data safety!
            # With 'off', you may lose last few transactions in a crash (but DB won't corrupt)
            CONFIG;
    }
}
