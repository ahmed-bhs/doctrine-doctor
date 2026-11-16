<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector\RowCount;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * @internal
 */
final class RowCountConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        ConnectionInterface $connection,
        private readonly RowCountRecorder $recorder,
        private readonly string $connectionName,
    ) {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new RowCountStatement(parent::prepare($sql), $this->recorder, $this->connectionName, $sql);
    }

    public function query(string $sql): Result
    {
        $result = parent::query($sql);
        $entry = $this->recorder->start($this->connectionName, $sql);

        return null === $entry ? $result : new RowCountResult($result, $entry);
    }
}
