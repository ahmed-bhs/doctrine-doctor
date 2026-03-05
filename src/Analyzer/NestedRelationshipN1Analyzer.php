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
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
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
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly int $threshold = 3,
        // Lowered from 5 to detect smaller nested patterns
        private readonly SqlStructureExtractor $sqlExtractor = new SqlStructureExtractor(),
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        $queries = $queryDataCollection->toArray();

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
        /** @var array<string, array{items: list<array{query: QueryData, table: string, foreignKey: ?string, sql: string, index: int}>, first_index: int}> $groupedByTable */
        $groupedByTable = [];

        // Group queries by table name
        foreach ($queries as $index => $query) {
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
                $groupedByTable[$table] = [
                    'items' => [],
                    'first_index' => (int) $index,
                ];
            }

            $groupedByTable[$table]['items'][] = [
                'query' => $query,
                'table' => $table,
                'foreignKey' => $foreignKey,
                'sql' => $sql,
                'index' => (int) $index,
            ];
        }

        // Filter tables with repeated queries (potential N+1)
        $repeatedTables = array_filter(
            $groupedByTable,
            fn (array $group): bool => \count($group['items']) >= $this->threshold,
        );

        if (\count($repeatedTables) < 2) {
            return []; // Need at least 2 tables for nesting
        }

        // Require at least one non-repeated root query before nested groups.
        // This prevents false positives from unrelated repeated lookups.
        $rootCandidates = array_filter(
            $groupedByTable,
            fn (array $group): bool => \count($group['items']) < $this->threshold,
        );

        if ([] === $rootCandidates) {
            return [];
        }

        $rootFirstIndex = min(array_map(static fn (array $group): int => $group['first_index'], $rootCandidates));
        $repeatedFirstIndex = min(array_map(static fn (array $group): int => $group['first_index'], $repeatedTables));

        if ($rootFirstIndex >= $repeatedFirstIndex) {
            return [];
        }

        $tablesArray = array_keys($repeatedTables);
        usort(
            $tablesArray,
            fn (string $tableA, string $tableB): int => $repeatedTables[$tableA]['first_index'] <=> $repeatedTables[$tableB]['first_index'],
        );

        $allQueries = [];
        $totalCount = 0;
        foreach ($tablesArray as $table) {
            $tableQueries = $repeatedTables[$table]['items'];
            $allQueries = array_merge($allQueries, array_map(fn (array $item): QueryData => $item['query'], $tableQueries));
            $totalCount += \count($tableQueries);
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
            type: IssueType::NESTED_N_PLUS_ONE->value,
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

        $totalImpact = $depth * $count;
        $severity = match (true) {
            $totalImpact >= 50 || $depth >= 4 => Severity::critical(),
            $totalImpact >= 20 || $depth >= 2 => Severity::warning(),
            default => Severity::info(),
        };

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/nested_eager_loading',
            context: [
                'entities' => $entities,
                'depth' => $depth,
                'query_count' => $count,
                'chain' => implode(' → ', $entities),
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $severity,
                title: sprintf('Nested N+1: %d Queries Across %d-Level Chain', $count, $depth),
                tags: ['performance', 'doctrine', 'n+1', 'nested', 'eager-loading'],
            ),
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
