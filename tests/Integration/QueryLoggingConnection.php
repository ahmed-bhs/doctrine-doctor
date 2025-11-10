<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Connection wrapper to intercept and log SQL queries.
 */
class QueryLoggingConnection implements DriverConnection
{
    public function __construct(
        /**
         * @readonly
         */
        private DriverConnection $driverConnection,
        /**
         * @readonly
         */
        private SimpleQueryLogger $simpleQueryLogger,
    ) {
    }

    public function prepare(string $sql): Statement
    {
        $statement = $this->driverConnection->prepare($sql);
        return new QueryLoggingStatement($statement, $sql, $this->simpleQueryLogger);
    }

    public function query(string $sql): Result
    {
        $this->simpleQueryLogger->log($sql);
        return $this->driverConnection->query($sql);
    }

    public function exec(string $sql): int
    {
        $this->simpleQueryLogger->log($sql);
        $result = $this->driverConnection->exec($sql);
        return is_int($result) ? $result : 0;
    }

    public function lastInsertId(): string|int
    {
        return $this->driverConnection->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->simpleQueryLogger->log('BEGIN TRANSACTION');
        $this->driverConnection->beginTransaction();
    }

    public function commit(): void
    {
        $this->simpleQueryLogger->log('COMMIT');
        $this->driverConnection->commit();
    }

    public function rollBack(): void
    {
        $this->simpleQueryLogger->log('ROLLBACK');
        $this->driverConnection->rollBack();
    }

    public function getNativeConnection(): object
    {
        $connection = $this->driverConnection->getNativeConnection();
        assert(is_object($connection));
        return $connection;
    }

    public function getServerVersion(): string
    {
        return $this->driverConnection->getServerVersion();
    }

    public function quote(string $value): string
    {
        return $this->driverConnection->quote($value);
    }
}
