<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Issue\OrderByNullableLeadingColumnIssue;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Schema\Column;

/**
 * Detects ORDER BY on a nullable column used as the leading sort key on a
 * bounded query (LIMIT / setMaxResults), independent of row count or EXPLAIN.
 *
 * Sorting by a nullable column is fine for a full, unbounded list: NULL
 * placement is cosmetic there. It stops being cosmetic the moment the query
 * is bounded to "pick the extremum" (oldest/latest/first matching row): NULL
 * placement (NULLS FIRST in MySQL/MariaDB ASC, engine-dependent elsewhere)
 * then decides which row is actually returned, silently, the day a NULL
 * appears in that column.
 */
class OrderByNullableLeadingColumnAnalyzer implements AnalyzerInterface
{
    /** @var array<string, list<Column>> */
    private array $tableColumnsCache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly SqlStructureExtractor $sqlStructureExtractor = new SqlStructureExtractor(),
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                $seenPatterns = [];

                foreach ($queryDataCollection->onlySelects() as $queryData) {
                    $pattern = $this->sqlStructureExtractor->normalizeQuery($queryData->sql);

                    if (isset($seenPatterns[$pattern])) {
                        continue;
                    }

                    $seenPatterns[$pattern] = true;

                    yield from $this->analyzeQuery($queryData);
                }
            },
        );
    }

    /**
     * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function analyzeQuery(QueryData $queryData): \Generator
    {
        $sql = $queryData->sql;

        if (!$this->sqlStructureExtractor->hasLimit($sql)) {
            return;
        }

        $leadingColumn = $this->extractLeadingOrderByColumn($sql);

        if (null === $leadingColumn) {
            return;
        }

        $table = $this->sqlStructureExtractor->extractMainTable($sql);
        $tableName = $table['table'] ?? null;

        if (!is_string($tableName) || '' === $tableName) {
            return;
        }

        $column = $this->findColumn($tableName, $leadingColumn);

        if (!$column instanceof Column) {
            return;
        }

        if ($column->getNotnull()) {
            return;
        }

        yield new OrderByNullableLeadingColumnIssue([
            'table' => $tableName,
            'column' => $column->getName(), // @phpstan-ignore method.internalClass
            'query' => $sql,
            'backtrace' => $queryData->backtrace,
            'queries' => [$queryData->toArray()],
        ]);
    }

    private function extractLeadingOrderByColumn(string $sql): ?string
    {
        $columns = $this->sqlStructureExtractor->extractOrderByColumnNames($sql);

        return $columns[0] ?? null;
    }

    private function findColumn(string $tableName, string $columnName): ?Column
    {
        foreach ($this->getTableColumns($tableName) as $column) {
            if (strtolower($column->getName()) === strtolower($columnName)) { // @phpstan-ignore method.internalClass
                return $column;
            }
        }

        return null;
    }

    /**
     * @return list<Column>
     */
    private function getTableColumns(string $tableName): array
    {
        if (isset($this->tableColumnsCache[$tableName])) {
            return $this->tableColumnsCache[$tableName];
        }

        try {
            $schemaManager = $this->connection->createSchemaManager();
            $columns = array_values($schemaManager->listTableColumns($tableName));
        } catch (DbalException) {
            $columns = [];
        }

        $this->tableColumnsCache[$tableName] = $columns;

        return $columns;
    }
}
