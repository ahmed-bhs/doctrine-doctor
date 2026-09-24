<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
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
    /**
     * Bound values and types, keyed like Symfony's profiler: positional parameters from 0.
     * @var array<int|string, mixed>
     */
    private array $params = [];

    /**
     * @var array<int|string, ParameterType>
     */
    private array $types = [];

    public function __construct(
        private readonly Statement $wrappedStatement,
        private readonly string $sql,
        private readonly SimpleQueryLogger $simpleQueryLogger,
    ) {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type = ParameterType::STRING): void
    {
        $this->wrappedStatement->bindValue($param, $value, $type);

        $index                = \is_int($param) ? $param - 1 : $param;
        $this->params[$index] = $value;
        $this->types[$index]  = $type;
    }

    public function execute(): Result
    {
        // Log the SQL query when it's executed
        $this->simpleQueryLogger->log($this->sql, $this->params, $this->types);
        return $this->wrappedStatement->execute();
    }
}
