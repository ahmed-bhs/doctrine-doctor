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
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\MissingIndexIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Schema\Index;

/**
 * Detects missing indexes by comparing WHERE-equality columns against the
 * real schema, independent of row count or EXPLAIN output.
 *
 * Unlike MissingIndexAnalyzer (EXPLAIN-based, requires slow/repetitive
 * queries and enough rows to trigger), this analyzer flags every SELECT
 * whose leading WHERE-equality column has no index starting with it, even
 * on a table with a handful of rows. The issue is latent today and becomes
 * a real full table scan once the table grows.
 */
class StructuralMissingIndexAnalyzer implements AnalyzerInterface
{
    /** @var array<string, list<Index>> */
    private array $tableIndexesCache = [];

    public function __construct(
        private readonly SuggestionFactoryInterface $suggestionFactory,
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
        $table = $this->sqlStructureExtractor->extractMainTable($queryData->sql);

        if (null === $table) {
            return;
        }

        $tableName = $table['table'] ?? null;

        if (!is_string($tableName) || '' === $tableName) {
            return;
        }

        $equalityColumns = $this->extractEqualityColumns($queryData->sql);

        if ([] === $equalityColumns) {
            return;
        }

        $indexes = $this->getTableIndexes($tableName);

        if ([] === $indexes && !$this->tableExists($tableName)) {
            return;
        }

        foreach ($equalityColumns as $column) {
            if ($this->isLeadingColumnOfAnyIndex($column, $indexes)) {
                continue;
            }

            yield $this->createIssue($tableName, $column, $queryData);
        }
    }

    /**
     * @return list<string>
     */
    private function extractEqualityColumns(string $sql): array
    {
        $conditions = $this->sqlStructureExtractor->extractWhereConditions($sql);

        $columns = [];

        foreach ($conditions as $condition) {
            if ('=' !== $condition['operator']) {
                continue;
            }

            $columns[] = strtolower($condition['column']);
        }

        return array_values(array_unique($columns));
    }

    /**
     * @return list<Index>
     */
    private function getTableIndexes(string $tableName): array
    {
        if (isset($this->tableIndexesCache[$tableName])) {
            return $this->tableIndexesCache[$tableName];
        }

        try {
            $schemaManager = $this->connection->createSchemaManager();
            $indexes = array_values($schemaManager->listTableIndexes($tableName));
        } catch (DbalException) {
            $indexes = [];
        }

        $this->tableIndexesCache[$tableName] = $indexes;

        return $indexes;
    }

    private function tableExists(string $tableName): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist([$tableName]);
        } catch (DbalException) {
            return false;
        }
    }

    /**
     * @param list<Index> $indexes
     */
    private function isLeadingColumnOfAnyIndex(string $column, array $indexes): bool
    {
        foreach ($indexes as $index) {
            $indexColumns = $index->getColumns();
            $leadingColumn = $indexColumns[0] ?? null;

            if (null !== $leadingColumn && strtolower($leadingColumn) === $column) {
                return true;
            }

            if ($index->isPrimary() && strtolower($leadingColumn ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    private function createIssue(string $tableName, string $column, QueryData $queryData): MissingIndexIssue
    {
        $indexName = 'IDX_' . strtoupper($tableName) . '_' . strtoupper($column);

        return new MissingIndexIssue([
            'table' => $tableName,
            'query' => $queryData->sql,
            'rows_scanned' => 0,
            'severity' => Severity::info(),
            'backtrace' => $queryData->backtrace,
            'queries' => [$queryData->toArray()],
            'description' => sprintf(
                'Query on table "%s" filters by "%s" without a leading index. Harmless today, becomes a full table scan as the table grows.',
                $tableName,
                $column,
            ),
            'suggestion' => $this->suggestionFactory->createFromTemplate(
                'missing_index',
                [
                    'table_display' => $tableName,
                    'real_table_name' => $tableName,
                    'columns_list' => $column,
                    'index_name' => $indexName,
                ],
                new SuggestionMetadata(
                    type: SuggestionType::performance(),
                    severity: Severity::info(),
                    title: sprintf('Missing Index on %s', $tableName),
                    tags: ['performance', 'index', 'database', 'structural'],
                ),
            ),
        ]);
    }
}
