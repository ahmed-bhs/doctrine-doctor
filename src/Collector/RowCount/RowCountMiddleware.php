<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector\RowCount;

use Doctrine\Bundle\DoctrineBundle\Middleware\ConnectionNameAwareInterface;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware;

final class RowCountMiddleware implements Middleware, ConnectionNameAwareInterface
{
    private string $connectionName = 'default';

    public function __construct(
        private readonly RowCountRecorder $recorder,
    ) {
    }

    public function setConnectionName(string $name): void
    {
        $this->connectionName = $name;
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new RowCountDriver($driver, $this->recorder, $this->connectionName);
    }
}
