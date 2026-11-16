<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector\RowCount;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Rows read by each SELECT of the request, per connection, in execution order.
 * The profiler records the SQL but not how many rows came back.
 */
final class RowCountRecorder implements ResetInterface
{
    private const int MAX_ENTRIES = 5000;

    /** @var array<string, list<RowCountEntry>> */
    private array $entries = [];

    private int $total = 0;

    public function start(string $connection, string $sql): ?RowCountEntry
    {
        if ($this->total >= self::MAX_ENTRIES || 1 !== preg_match('/^\s*(\(\s*)*(SELECT|WITH)\b/i', $sql)) {
            return null;
        }

        ++$this->total;
        $entry = new RowCountEntry($sql);
        $this->entries[$connection][] = $entry;

        return $entry;
    }

    /**
     * @return list<RowCountEntry>
     */
    public function entries(string $connection): array
    {
        return $this->entries[$connection] ?? [];
    }

    public function reset(): void
    {
        $this->entries = [];
        $this->total = 0;
    }
}
