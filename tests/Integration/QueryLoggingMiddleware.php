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
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL Middleware to intercept and log all SQL queries.
 * Compatible with Doctrine DBAL 3.x and 4.x.
 */
class QueryLoggingMiddleware implements Middleware
{
    public function __construct(
        /**
         * @readonly
         */
        private SimpleQueryLogger $simpleQueryLogger,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new QueryLoggingDriver($driver, $this->simpleQueryLogger);
    }
}
