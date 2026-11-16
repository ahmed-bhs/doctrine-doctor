<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector\RowCount;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * @internal
 */
final class RowCountStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $statement,
        private readonly RowCountRecorder $recorder,
        private readonly string $connectionName,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $result = parent::execute();
        $entry = $this->recorder->start($this->connectionName, $this->sql);

        return null === $entry ? $result : new RowCountResult($result, $entry);
    }
}
