<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

/**
 * Statement wrapper to log query execution with parameters.
 */
class QueryLoggingStatement implements Statement
{
    public function __construct(
        /**
         * @readonly
         */
        private Statement $wrappedStatement,
        /**
         * @readonly
         */
        private string $sql,
        /**
         * @readonly
         */
        private SimpleQueryLogger $simpleQueryLogger,
    ) {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type = ParameterType::STRING): void
    {
        $this->wrappedStatement->bindValue($param, $value, $type);
    }

    public function execute(): Result
    {
        // Log the SQL query when it's executed
        $this->simpleQueryLogger->log($this->sql);
        return $this->wrappedStatement->execute();
    }
}
