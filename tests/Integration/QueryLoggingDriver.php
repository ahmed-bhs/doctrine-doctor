<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\ServerVersionProvider;

/**
 * Driver wrapper to intercept connection creation.
 */
class QueryLoggingDriver implements Driver
{
    public function __construct(
        /**
         * @readonly
         */
        private Driver $wrappedDriver,
        /**
         * @readonly
         */
        private SimpleQueryLogger $simpleQueryLogger,
    ) {
    }

    public function connect(array $params): DriverConnection
    {
        $connection = $this->wrappedDriver->connect($params);
        return new QueryLoggingConnection($connection, $this->simpleQueryLogger);
    }

    public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
    {
        return $this->wrappedDriver->getDatabasePlatform($versionProvider);
    }

    public function getSchemaManager(\Doctrine\DBAL\Connection $connection, AbstractPlatform $platform): AbstractSchemaManager
    {
        return $this->wrappedDriver->getSchemaManager($connection, $platform); // @phpstan-ignore-line method.notFound
    }

    public function getExceptionConverter(): ExceptionConverter
    {
        return $this->wrappedDriver->getExceptionConverter();
    }
}
