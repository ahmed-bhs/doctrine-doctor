<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\DTO;

use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use Webmozart\Assert\Assert;

/**
 * Data Transfer Object representing a database query.
 * Immutable and type-safe replacement for associative arrays.
 */
class QueryData
{
    /**
     * @param array<string, mixed>                  $params    Query parameters
     * @param array<int, array<string, mixed>>|null $backtrace Stack trace of query execution
     */
    public function __construct(
        /**
         * @readonly
         */
        public string $sql,
        /**
         * @readonly
         */
        public QueryExecutionTime $executionTime,
        /** @var array<mixed>
         * @readonly */
        public array $params = [],
        /**
         * @readonly
         */
        public ?array $backtrace = null,
        /**
         * @readonly
         */
        public ?int $rowCount = null,
    ) {
        Assert::stringNotEmpty($sql, 'SQL query cannot be empty');
        Assert::isInstanceOf($executionTime, QueryExecutionTime::class, 'Execution time must be an instance of QueryExecutionTime');
        Assert::isArray($params, 'Query parameters must be an array');

        if (null !== $backtrace) {
            Assert::isArray($backtrace, 'Backtrace must be an array or null');
        }

        if (null !== $rowCount) {
            Assert::greaterThanEq($rowCount, 0, 'Row count must be non-negative, got %s');
        }
    }

    /**
     * Create from array (legacy compatibility).
     * @param array<string, mixed> $data
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public static function fromArray(array $data): self
    {
        $rawExecutionTime = $data['executionMS'] ?? 0.0;
        $executionTime = is_numeric($rawExecutionTime) ? (float) $rawExecutionTime : 0.0;

        // IMPORTANT: Despite the field name 'executionMS', Symfony's Doctrine middleware
        // actually stores duration in SECONDS (see Symfony\Bridge\Doctrine\Middleware\Debug\Query::getDuration()).
        // However, some contexts (tests, legacy code) may already provide milliseconds.
        // Heuristic: values between 0 and 1 are likely seconds, values >= 1 are likely milliseconds.
        if ($executionTime > 0 && $executionTime < 1) {
            // Likely in seconds, convert to milliseconds
            $executionMs = $executionTime * 1000;
        } else {
            // Already in milliseconds
            $executionMs = $executionTime;
        }

        // Handle both 'rowCount' and 'row_count' (Doctrine inconsistency)
        $rowCount = $data['rowCount'] ?? $data['row_count'] ?? null;

        // Convert VarDumper Data objects to arrays (from Doctrine profiler)
        $params = $data['params'] ?? [];

        if (is_object($params) && method_exists($params, 'getValue')) {
            $params = $params->getValue(true);
        }

        if (!is_array($params)) {
            $params = [];
        }

        $sql = $data['sql'] ?? '';
        $backtrace = $data['backtrace'] ?? null;

        /** @var array<int, array<string, mixed>>|null $validBacktrace */
        $validBacktrace = null !== $backtrace && is_array($backtrace) ? $backtrace : null;

        return new self(
            sql: is_string($sql) ? $sql : '',
            executionTime: QueryExecutionTime::fromMilliseconds($executionMs),
            params: $params,
            backtrace: $validBacktrace,
            rowCount: null !== $rowCount && is_numeric($rowCount) ? (int) $rowCount : null,
        );
    }

    /**
     * Convert to array (for serialization).
     * @return array{sql: string, executionMS: float, params: array<string, mixed>, backtrace: array<int, array<string, mixed>>|null, rowCount: int|null}
     */
    public function toArray(): array
    {
        return [
            'sql'         => $this->sql,
            'executionMS' => $this->executionTime->inMilliseconds(),
            'params'      => $this->params,
            'backtrace'   => $this->backtrace,
            'rowCount'    => $this->rowCount,
        ];
    }

    public function isSlow(float $thresholdMs = 100.0): bool
    {
        Assert::greaterThan($thresholdMs, 0, 'Threshold must be positive, got %s');

        return $this->executionTime->isSlow($thresholdMs);
    }

    public function isSelect(): bool
    {
        return 0 === stripos(trim($this->sql), 'SELECT');
    }

    public function isInsert(): bool
    {
        return 0 === stripos(trim($this->sql), 'INSERT');
    }

    public function isUpdate(): bool
    {
        return 0 === stripos(trim($this->sql), 'UPDATE');
    }

    public function isDelete(): bool
    {
        return 0 === stripos(trim($this->sql), 'DELETE');
    }

    public function getQueryType(): string
    {
        $sql = trim($this->sql);

        return match (true) {
            0 === stripos($sql, 'SELECT') => 'SELECT',
            0 === stripos($sql, 'INSERT') => 'INSERT',
            0 === stripos($sql, 'UPDATE') => 'UPDATE',
            0 === stripos($sql, 'DELETE') => 'DELETE',
            default                       => 'OTHER',
        };
    }
}
