<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector\Helper;

use AhmedBhs\DoctrineDoctor\Collector\RowCount\RowCountRecorder;

/**
 * Adds to the profiler queries what the profiler does not record: the rows each SELECT
 * returned, and how many entities were hydrated from the tables it read.
 */
final readonly class QueryEnricher
{
    public function __construct(
        private ?RowCountRecorder $rowCounts,
    ) {
    }

    /**
     * @param array<mixed> $queries
     * @return array<mixed>
     */
    public function enrich(string $connection, array $queries): array
    {
        return $this->withHydratedEntities($this->withRowCounts($connection, $queries));
    }

    /**
     * Adds the rows each SELECT returned, matched by SQL in execution order.
     * @param array<mixed> $queries
     * @return array<mixed>
     */
    private function withRowCounts(string $connection, array $queries): array
    {
        if (!$this->rowCounts instanceof RowCountRecorder) {
            return $queries;
        }

        $bySql = [];
        foreach ($this->rowCounts->entries($connection) as $entry) {
            $bySql[$entry->sql][] = $entry->rows;
        }

        foreach ($queries as $index => $query) {
            if (!is_array($query) || !is_string($query['sql'] ?? null) || isset($query['rowCount']) || [] === ($bySql[$query['sql']] ?? [])) {
                continue;
            }

            $queries[$index]['rowCount'] = array_shift($bySql[$query['sql']]);
        }

        return $queries;
    }

    /**
     * @param array<mixed> $queries
     * @return array<mixed>
     */
    private function withHydratedEntities(array $queries): array
    {
        if (!$this->rowCounts instanceof RowCountRecorder) {
            return $queries;
        }

        $hydratedByTable = $this->rowCounts->hydratedByTable();

        foreach ($queries as $index => $query) {
            if (!is_array($query) || !isset($query['rowCount']) || !is_string($query['sql'] ?? null)) {
                continue;
            }

            preg_match_all('/\b(?:FROM|JOIN)\s+`?(\w+)`?/i', $query['sql'], $tables);
            $queries[$index]['hydratedEntities'] = array_sum(array_map(
                static fn (string $table): int => $hydratedByTable[strtolower($table)] ?? 0,
                array_unique($tables[1]),
            ));
        }

        return $queries;
    }
}
