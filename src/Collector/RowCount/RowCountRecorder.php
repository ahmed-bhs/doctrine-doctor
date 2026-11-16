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
 * Rows read by each SELECT of the request, per connection, in execution order, and the
 * entities hydrated from them. The profiler records the SQL but neither of these.
 */
final class RowCountRecorder implements ResetInterface
{
    private const int MAX_ENTRIES = 5000;

    /** @var array<string, list<RowCountEntry>> */
    private array $entries = [];

    private int $total = 0;

    /** @var array<string, int> */
    private array $hydrated = [];

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

    public function entityHydrated(string $table): void
    {
        $table = strtolower($table);
        $this->hydrated[$table] = ($this->hydrated[$table] ?? 0) + 1;
    }

    /**
     * Entities hydrated during the request, per table, even if cleared since.
     * @return array<string, int>
     */
    public function hydratedByTable(): array
    {
        return $this->hydrated;
    }

    public function reset(): void
    {
        $this->entries = [];
        $this->hydrated = [];
        $this->total = 0;
    }
}
