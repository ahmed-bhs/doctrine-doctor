<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector\RowCount;

use Doctrine\DBAL\Driver\Middleware\AbstractResultMiddleware;
use Doctrine\DBAL\Driver\Result;

/**
 * @internal
 */
final class RowCountResult extends AbstractResultMiddleware
{
    public function __construct(
        Result $result,
        private readonly RowCountEntry $entry,
    ) {
        parent::__construct($result);
    }

    public function fetchNumeric(): array|false
    {
        return $this->counted(parent::fetchNumeric());
    }

    public function fetchAssociative(): array|false
    {
        return $this->counted(parent::fetchAssociative());
    }

    public function fetchOne(): mixed
    {
        return $this->counted(parent::fetchOne());
    }

    public function fetchAllNumeric(): array
    {
        return $this->countedAll(parent::fetchAllNumeric());
    }

    public function fetchAllAssociative(): array
    {
        return $this->countedAll(parent::fetchAllAssociative());
    }

    public function fetchFirstColumn(): array
    {
        return $this->countedAll(parent::fetchFirstColumn());
    }

    /**
     * @template T
     * @param T $row
     * @return T
     */
    private function counted(mixed $row): mixed
    {
        if (false !== $row) {
            ++$this->entry->rows;
        }

        return $row;
    }

    /**
     * @template T of array
     * @param T $rows
     * @return T
     */
    private function countedAll(array $rows): array
    {
        $this->entry->rows += \count($rows);

        return $rows;
    }
}
