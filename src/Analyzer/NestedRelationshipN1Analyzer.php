<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Webmozart\Assert\Assert;

/**
 * Detects nested relationship N+1 queries.
 *
 * Example of nested N+1:
 * ```php
 * foreach ($articles as $article) {
 *     echo $article->getAuthor()->getCountry()->getName(); // 2-hop N+1!
 * }
 * ```
 *
 * This creates N+1 queries for authors AND N+1 queries for countries.
 *
 * Inspired by nplusone's nested relationship tracking.
 * In static analysis, we detect this by finding query chains where:
 * - Multiple N+1 patterns occur in sequence
 * - Foreign keys form a chain (article.author_id → user.country_id)
 * - Queries follow a temporal pattern suggesting nested access
 */
class NestedRelationshipN1Analyzer implements AnalyzerInterface
{
    /** @var int At least 2-level nesting to report */
    private const int MIN_CHAIN_LENGTH = 2;

    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly int $threshold = 3,
        // Lowered from 5 to detect smaller nested patterns
        private readonly SqlStructureExtractor $sqlExtractor = new SqlStructureExtractor(),
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        $queries = $queryDataCollection->toArray();

        // Sort by execution time to analyze temporal sequences
        usort($queries, function (QueryData $queryA, QueryData $queryB): int {
            return 0; // If no timing available, keep original order
        });

        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queries) {
                $chains = $this->detectQueryChains($queries);

                Assert::isIterable($chains, '$chains must be iterable');

                foreach ($chains as $chain) {
                    if ($chain['depth'] >= self::MIN_CHAIN_LENGTH && $chain['count'] >= $this->threshold) {
                        $issueData = $this->createNestedN1Issue($chain);
                        yield $this->issueFactory->create($issueData);
                    }
                }
            },
        );
    }

    /**
     * Detect query chains that suggest nested relationship access.
     *
     * Strategy:
     * 1. Extract all SELECT queries and identify which table each queries
     * 2. Group queries by table to count repetitions
     * 3. Identify sequences where multiple tables are repeatedly queried
     * 4. Build chains based on foreign key patterns in WHERE clauses
     *
     * @param array<QueryData> $queries
     *
     * @return array<array{depth: int, count: int, tables: array<string>, pattern: string, queries: array<QueryData>}>
     */
    private function detectQueryChains(array $queries): array
    {
        $chains = [];
        $groupedByTable = [];

        // Group queries by table name
        foreach ($queries as $query) {
            $sql = $query->sql;

            // Only analyze SELECT queries
            if (!$this->sqlExtractor->isSelectQuery($sql)) {
                continue;
            }

            // Extract table from query
            $tables = $this->sqlExtractor->getAllTableNames($sql);
            if (empty($tables)) {
                continue;
            }

            // For simple queries, use the first/main table
            $table = $tables[0]; // Already lowercase from getAllTableNames()

            // Extract foreign key pattern if present (WHERE table_id = ?)
            $foreignKey = $this->extractForeignKeyPattern($sql);

            if (!isset($groupedByTable[$table])) {
                $groupedByTable[$table] = [];
            }

            $groupedByTable[$table][] = [
                'query' => $query,
                'table' => $table,
                'foreignKey' => $foreignKey,
                'sql' => $sql,
            ];
        }

        // Filter tables with repeated queries (potential N+1)
        $repeatedTables = array_filter($groupedByTable, fn ($group) => \count($group) >= $this->threshold);

        if (\count($repeatedTables) < 2) {
            return []; // Need at least 2 tables for nesting
        }

        // Simplified approach: If we have 2+ tables with repeated queries,
        // assume they form a nested relationship chain
        // This is a heuristic - in real scenarios, these are likely related
        $tablesArray = array_keys($repeatedTables);

        if (\count($tablesArray) >= 2) {
            // Build chain from all repeated tables
            // Sort by query count (ascending) to get the likely execution order
            usort($tablesArray, fn (string $tableA, string $tableB): int => \count($repeatedTables[$tableA]) <=> \count($repeatedTables[$tableB]));

            // Reverse to get parent -> child order (more queries first)
            $tablesArray = array_reverse($tablesArray);

            $allQueries = [];
            $totalCount = 0;
            foreach ($tablesArray as $table) {
                $queries = $repeatedTables[$table];
                $allQueries = array_merge($allQueries, array_map(fn ($item) => $item['query'], $queries));
                $totalCount += \count($queries);
            }

            // Calculate average query count per table
            $avgCount = (int) ($totalCount / \count($tablesArray));

            $chains[] = [
                'depth' => \count($tablesArray),
                'count' => $avgCount,
                'tables' => $tablesArray,
                'pattern' => implode(' → ', $tablesArray),
                'queries' => $allQueries,
            ];
        }

        return $chains;
    }

    /**
     * Extract foreign key pattern from WHERE clause.
     * E.g., "WHERE author_id = ?" returns "author_id"
     * E.g., "WHERE id = ?" returns "id"
     *
     * Migrated from regex to SQL parser for better accuracy.
     */
    private function extractForeignKeyPattern(string $sql): ?string
    {
        try {
            // Use SQL parser to extract WHERE columns (avoids false positives from comments/strings)
            $columns = $this->sqlExtractor->extractWhereColumns($sql);

            if (empty($columns)) {
                return null;
            }

            // Return the first column found in WHERE clause
            // For nested N+1 detection, this is typically the foreign key
            return $columns[0];

        } catch (\Throwable) {
            // Fallback to regex if parser fails (malformed SQL)
            if (1 === preg_match('/WHERE\s+(\w+)\s*(?:=|IN)\s*[?:\d(]/i', $sql, $matches)) {
                return strtolower($matches[1]);
            }

            return null;
        }
    }

    /**
     * @param array{depth: int, count: int, tables: array<string>, pattern: string, queries: array<QueryData>} $chain
     */
    private function createNestedN1Issue(array $chain): IssueData
    {
        $depth = $chain['depth'];
        $count = $chain['count'];
        $pattern = $chain['pattern'];
        $tables = $chain['tables'];

        $description = DescriptionHighlighter::highlight(
            'Nested relationship N+1 detected: {count} queries across {depth}-level relationship chain: {pattern}',
            [
                'count' => $count,
                'depth' => $depth,
                'pattern' => $pattern,
            ],
        );

        $description .= "\n\nThis occurs when accessing nested relationships in a loop:";
        $description .= "\n\$entity->getRelation1()->getRelation2()->getValue()";
        $description .= "\n\nEach level of nesting multiplies the queries:";
        $description .= "\n- Level 1: {$count} queries for " . $tables[0];
        if (isset($tables[1])) {
            $description .= "\n- Level 2: {$count} additional queries for " . $tables[1];
        }
        $description .= "\n- Total impact: " . ($count * $depth) . ' queries!';

        return new IssueData(
            type: 'nested_n_plus_one',
            title: "Nested N+1: {$count} Queries Across {$depth}-Level Chain",
            description: $description,
            severity: $this->calculateNestedSeverity($depth, $count),
            suggestion: $this->createNestedSuggestion($chain),
            queries: $chain['queries'],
            backtrace: $chain['queries'][0]->backtrace ?? null,
        );
    }

    /**
     * @param array{depth: int, count: int, tables: array<string>, pattern: string, queries: array<QueryData>} $chain
     */
    private function createNestedSuggestion(array $chain): ?SuggestionInterface
    {
        $tables = $chain['tables'];
        $depth = $chain['depth'];
        $count = $chain['count'];

        // Convert tables to entity names (simplified)
        $entities = array_map($this->tableToEntity(...), $tables);

        return $this->suggestionFactory->createNestedEagerLoading(
            entities: $entities,
            depth: $depth,
            queryCount: $count,
        );
    }

    private function calculateNestedSeverity(int $depth, int $count): Severity
    {
        // Nested N+1 is more severe because it multiplies queries
        $totalImpact = $depth * $count;

        if ($totalImpact >= 50 || $depth >= 4) {
            return Severity::critical(); // Very deep nesting or high count
        }

        if ($totalImpact >= 20 || $depth >= 2) {
            return Severity::warning();
        }

        return Severity::info();
    }

    private function tableToEntity(string $table): string
    {
        // Simple conversion: snake_case to PascalCase
        return str_replace('_', '', ucwords($table, '_'));
    }
}
