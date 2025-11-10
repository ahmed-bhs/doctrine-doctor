<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Utils;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Result;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * Detects database platform and version for compatibility checks.
 * Supports MySQL, MariaDB, PostgreSQL across Doctrine DBAL 2.x and 3.x+.
 */
class DatabasePlatformDetector
{
    public function __construct(
        /**
         * @readonly
         */
        private Connection $connection,
    ) {
    }

    /**
     * Check if the platform is MySQL or MariaDB.
     */
    public function isMySQLFamily(): bool
    {
        return in_array($this->getPlatformName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Check if the platform is specifically MariaDB.
     */
    public function isMariaDB(): bool
    {
        if ('mariadb' === $this->getPlatformName()) {
            return true;
        }

        // Fallback: check version string for MariaDB
        $version = $this->getServerVersion();

        return false !== stripos($version, 'mariadb');
    }

    /**
     * Check if the platform is PostgreSQL.
     */
    public function isPostgreSQL(): bool
    {
        return 'postgresql' === $this->getPlatformName();
    }

    /**
     * Check if the platform is SQLite.
     */
    public function isSQLite(): bool
    {
        return 'sqlite' === $this->getPlatformName();
    }

    /**
     * Get platform name (normalized: mysql, mariadb, postgresql, sqlite, etc.).
     */
    public function getPlatformName(): string
    {
        $platform = $this->connection->getDatabasePlatform();
        $platformClass = get_class($platform);

        // Use class name string comparison for compatibility with all Doctrine DBAL versions
        if ($platform instanceof PostgreSQLPlatform) {
            return 'postgresql';
        }

        if ($platform instanceof SQLitePlatform) {
            return 'sqlite';
        }

        if ($platform instanceof SQLServerPlatform) {
            return 'sqlserver';
        }

        if ($platform instanceof OraclePlatform) {
            return 'oracle';
        }

        // MariaDB detection (Doctrine 3.2+) - use string comparison to avoid class_notFound
        if (str_contains($platformClass, 'MariaDB')) {
            return 'mariadb';
        }

        // MySQL variants - use string comparison for version-specific platforms
        if ($platform instanceof MySQLPlatform || str_contains($platformClass, 'MySQL')) {
            return $this->detectMariaDBFromVersion() ? 'mariadb' : 'mysql';
        }

        // Fallback: try to use class name or get a generic name
        return strtolower(basename(str_replace('\\', '/', $platformClass)));
    }

    /**
     * Get server version string.
     */
    public function getServerVersion(): string
    {
        try {
            // Try getServerVersion() first (available in both DBAL 2.x and 3.x)
            if (method_exists($this->connection, 'getServerVersion')) {
                return $this->connection->getServerVersion();
            }

            // Fallback to direct query
            if ($this->isMySQLFamily()) {
                $result = $this->connection->executeQuery('SELECT VERSION()')->fetchOne();

                return is_string($result) ? $result : 'unknown';
            }

            // Fallback to direct query
            if ($this->isPostgreSQL()) {
                $result = $this->connection->executeQuery('SELECT version()')->fetchOne();

                return is_string($result) ? $result : 'unknown';
            }

            return 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * Check if a specific feature is supported.
     */
    public function supportsFeature(string $feature): bool
    {
        return match ($feature) {
            'explain'            => $this->isMySQLFamily() || $this->isPostgreSQL(),
            'explain_analyze'    => $this->isPostgreSQL(),
            'sql_mode'           => $this->isMySQLFamily(),
            'innodb'             => $this->isMySQLFamily(),
            'charset_utf8mb4'    => $this->isMySQLFamily(),
            'information_schema' => $this->isMySQLFamily() || $this->isPostgreSQL(),
            'show_variables'     => $this->isMySQLFamily(),
            'pg_stat_statements' => $this->isPostgreSQL(),
            default              => false,
        };
    }

    /**
     * Execute a query with platform-specific compatibility.
     * @return mixed Query result
     */
    public function executeCompatibleQuery(string $mysqlQuery, ?string $postgresQuery = null, ?string $defaultQuery = null): mixed
    {
        Assert::stringNotEmpty($mysqlQuery, 'MySQL query cannot be empty');

        try {
            if ($this->isMySQLFamily()) {
                return $this->connection->executeQuery($mysqlQuery);
            }

            if ($this->isPostgreSQL() && null !== $postgresQuery) {
                return $this->connection->executeQuery($postgresQuery);
            }

            if (null !== $defaultQuery) {
                return $this->connection->executeQuery($defaultQuery);
            }

            throw new RuntimeException('No compatible query for platform: ' . $this->getPlatformName());
        } catch (\Throwable $throwable) {
            throw new RuntimeException(sprintf('Failed to execute compatible query on %s: %s', $this->getPlatformName(), $throwable->getMessage()), (int) $throwable->getCode(), previous: $throwable);
        }
    }

    /**
     * Get the EXPLAIN query syntax for this platform.
     */
    public function getExplainQuery(string $query): string
    {
        return match (true) {
            $this->isMySQLFamily() => 'EXPLAIN ' . $query,
            $this->isPostgreSQL()  => 'EXPLAIN ' . $query,
            $this->isSQLite()      => 'EXPLAIN QUERY PLAN ' . $query,
            default                => throw new RuntimeException('EXPLAIN not supported on platform: ' . $this->getPlatformName()),
        };
    }

    /**
     * Check if we're using Doctrine DBAL 3.x or higher.
     */
    public function isDBAL3OrHigher(): bool
    {
        return interface_exists(Result::class);
    }

    /**
     * Fetch associative array with compatibility for DBAL 2.x, 3.x, and 4.x.
     */
    public function fetchAssociative(Result|Statement $result): array|false
    {
        // DBAL 3.x and 4.x use Result with fetchAssociative()
        if ($result instanceof Result) {
            return $result->fetchAssociative();
        }

        // DBAL 2.x uses Statement with fetch()
        // @phpstan-ignore-next-line Method exists in DBAL 2.x Statement
        return $result->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Fetch all rows with compatibility for DBAL 2.x, 3.x, and 4.x.
     */
    public function fetchAllAssociative(Result|Statement $result): array
    {
        // DBAL 3.x and 4.x use Result with fetchAllAssociative()
        if ($result instanceof Result) {
            return $result->fetchAllAssociative();
        }

        // DBAL 2.x uses Statement with fetchAll()
        // @phpstan-ignore-next-line Method exists in DBAL 2.x Statement
        return $result->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Fetch single column with compatibility for DBAL 2.x, 3.x, and 4.x.
     */
    public function fetchOne(Result|Statement $result): mixed
    {
        // DBAL 3.x and 4.x use Result with fetchOne()
        if ($result instanceof Result) {
            return $result->fetchOne();
        }

        // DBAL 2.x uses Statement with fetchColumn()
        // @phpstan-ignore-next-line Method exists in DBAL 2.x Statement
        return $result->fetchColumn();
    }

    /**
     * Detect MariaDB from version string without using getPlatformName() to avoid recursion.
     */
    private function detectMariaDBFromVersion(): bool
    {
        try {
            // Try getServerVersion() first (available in both DBAL 2.x and 3.x)
            if (method_exists($this->connection, 'getServerVersion')) {
                $version = $this->connection->getServerVersion();
                return false !== stripos($version, 'mariadb');
            }

            // Fallback to direct query for MySQL/MariaDB
            $platform = $this->connection->getDatabasePlatform();
            if ($platform instanceof MySQLPlatform) {
                $result = $this->connection->executeQuery('SELECT VERSION()')->fetchOne();
                return is_string($result) && false !== stripos($result, 'mariadb');
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }
}
