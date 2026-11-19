<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Concern\QueryFieldAccessorTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Detects inconsistencies in JOIN type usage that can cause bugs or performance issues.
 * This analyzer takes a nuanced approach and detects:
 * 1. LEFT JOIN followed by IS NOT NULL check (should use INNER JOIN)
 * 2. COUNT/SUM with INNER JOIN on *-to-many (causes row duplication bugs)
 * 3. Suspicious patterns that may indicate join type misuse
 * We DO NOT blindly enforce "INNER for *-to-one, LEFT for *-to-many" as this
 * oversimplifies and can cause data loss bugs.
 */
class JoinTypeConsistencyAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    use QueryFieldAccessorTrait;

    /**
     * @var array<string, ClassMetadata>|null Cached metadata map
     */
    private ?array $metadataMapCache = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SqlStructureExtractor $sqlExtractor = new SqlStructureExtractor(),
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                $seenIssues = [];

                foreach ($queryDataCollection as $query) {
                    $sql = $this->extractSQL($query);
                    if ('' === $sql) {
                        continue;
                    }

                    if ('0' === $sql) {
                        continue;
                    }

                    // Pattern 1: LEFT JOIN with IS NOT NULL check
                    // Migration from regex to SQL Parser:
                    // - Use extractJoins() to find LEFT JOINs
                    // - Use hasIsNotNullOnAlias() to check for IS NOT NULL on joined table
                    $joins = $this->sqlExtractor->extractJoins($sql);

                    foreach ($joins as $join) {
                        // Only check LEFT JOINs
                        if ('LEFT' !== $join['type']) {
                            continue;
                        }

                        $tableName = $join['table'];
                        $alias = $join['alias'] ?? $tableName;

                        // Check if this LEFT JOIN has IS NOT NULL condition
                        $fieldName = $this->sqlExtractor->findIsNotNullFieldOnAlias($sql, $alias);
                        if (null !== $fieldName) {
                            $key = 'left_join_not_null_' . md5($tableName . $alias);
                            if (isset($seenIssues[$key])) {
                                continue;
                            }

                            $seenIssues[$key] = true;

                            yield $this->createLeftJoinWithNotNullIssue(
                                $tableName,
                                $alias,
                                $fieldName, // Field name extracted from IS NOT NULL condition
                                $query,
                            );
                        }
                    }

                    // Pattern 2: an aggregate whose rows are repeated by a to-many join (wrong SUM/COUNT/AVG)
                    $key = 'aggregation_inner_join_' . md5($sql);
                    if (isset($seenIssues[$key])) {
                        continue;
                    }

                    $finding = $this->findInflatedAggregate($sql);
                    if (null !== $finding) {
                        $seenIssues[$key] = true;

                        yield $this->createAggregationWithInnerJoinIssue($finding['function'], $sql, $query, $finding['distinct'], $finding);
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'JOIN Type Consistency Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects inconsistencies in JOIN usage that can cause bugs or performance issues';
    }

    /**
     * Extract SQL from query data.
     */

    /**
     * Create issue for LEFT JOIN with IS NOT NULL check.
     */
    private function createLeftJoinWithNotNullIssue(
        string $tableName,
        string $alias,
        string $field,
        array|object $query,
    ): IntegrityIssue {
        $backtrace = $this->extractBacktrace($query);

        $issueData = new IssueData(
            type: IssueType::LEFT_JOIN_WITH_NOT_NULL->value,
            title: 'LEFT JOIN with IS NOT NULL Check',
            description: sprintf(
                "The query uses LEFT JOIN on '%s' and then filters on '%s.%s IS NOT NULL'. " .
                "The filter drops the rows LEFT JOIN kept, so the query behaves as an INNER JOIN while hiding it from the planner.",
                $tableName,
                $alias,
                $field,
            ),
            severity: Severity::info(),
            suggestion: $this->createLeftJoinWithNotNullSuggestion($tableName, $field),
            queries: [],
            backtrace: $backtrace,
        );

        /** @var IntegrityIssue $issue */
        $issue = $this->issueFactory->createFromArray($issueData->toArray());
        return $issue;
    }

    /**
     * Create issue for aggregation with INNER JOIN.
     */
    /**
     * The first aggregate of the SELECT whose rows a to-many join repeats.
     *
     * A join "P (one) to C (many)" repeats every row of the tables on P's side once per matching C row. SUM(x.col),
     * AVG(x.col) or COUNT(x.col) is inflated when x sits on P's side of such a join. An aggregate over the many side
     * itself (SUM(o.total) with orders joined to their customer) is correct. When only DISTINCT aggregates are
     * repeated, the result is correct and the finding is informational.
     *
     * @return array{function: string, expression: string, alias: string, table: string, multiplier: string, distinct: bool}|null
     */
    private function findInflatedAggregate(string $sql): ?array
    {
        $select = preg_split('/\bFROM\b/i', $sql, 2)[0] ?? '';
        if (0 === preg_match_all('/\b(SUM|AVG|COUNT)\s*\(\s*(DISTINCT\s+)?(\w+)\.(\w+)\s*\)/i', $select, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $tables = $this->aliasTables($sql);
        $edges = $this->joinEdges($sql, $tables);
        if ([] === $edges) {
            return null;
        }

        $distinctOnly = null;
        foreach ($matches as $match) {
            $alias = strtolower($match[3]);
            if (!isset($tables[$alias])) {
                continue;
            }

            foreach ($edges as $edge) {
                if (null === $edge['one'] || $this->sameSide($alias, $edge, $edges)) {
                    continue;
                }

                $finding = [
                    'function' => strtoupper($match[1]),
                    'expression' => $match[0],
                    'alias' => $alias,
                    'table' => $tables[$alias],
                    'multiplier' => $tables[$edge['many']],
                    'distinct' => '' !== trim($match[2]),
                ];

                if (!$finding['distinct']) {
                    return $finding;
                }
                $distinctOnly ??= $finding;
            }
        }

        return $distinctOnly;
    }

    /**
     * True when $alias is on the many side of $edge, i.e. still connected to the many table once the edge is cut.
     *
     * @param array{one: ?string, many: string, a: string, b: string} $edge
     * @param list<array{one: ?string, many: string, a: string, b: string}> $edges
     */
    private function sameSide(string $alias, array $edge, array $edges): bool
    {
        $seen = [$edge['many'] => true];
        $queue = [$edge['many']];
        while ([] !== $queue) {
            $current = array_shift($queue);
            foreach ($edges as $other) {
                if ($other === $edge) {
                    continue;
                }
                foreach ([[$other['a'], $other['b']], [$other['b'], $other['a']]] as [$from, $to]) {
                    if ($from === $current && !isset($seen[$to])) {
                        $seen[$to] = true;
                        $queue[] = $to;
                    }
                }
            }
        }

        return isset($seen[$alias]);
    }

    /**
     * @return array<string, string> alias (lowercase) => table
     */
    private function aliasTables(string $sql): array
    {
        $tables = [];
        if (1 === preg_match('/\bFROM\s+`?(\w+)`?(?:\s+(?:AS\s+)?(?!WHERE|JOIN|INNER|LEFT|RIGHT|GROUP|ORDER|LIMIT)(\w+))?/i', $sql, $from)) {
            $tables[strtolower($from[2] ?? $from[1])] = $from[1];
        }
        foreach ($this->sqlExtractor->extractJoins($sql) as $join) {
            if (\is_string($join['table'])) {
                $alias = \is_string($join['alias']) && '' !== $join['alias'] ? $join['alias'] : $join['table'];
                $tables[strtolower($alias)] = $join['table'];
            }
        }

        return $tables;
    }

    /**
     * Equalities between two aliases in the ON clauses, with the side that is unique (the "one" side) when known.
     *
     * @param array<string, string> $tables
     * @return list<array{one: ?string, many: string, a: string, b: string}>
     */
    private function joinEdges(string $sql, array $tables): array
    {
        $edges = [];
        foreach ($this->sqlExtractor->extractJoins($sql) as $join) {
            $onClause = \is_object($join['expr']) && property_exists($join['expr'], 'on') && \is_array($join['expr']->on)
                ? implode(' AND ', array_map(static fn (mixed $condition): string => \is_scalar($condition) || $condition instanceof \Stringable ? (string) $condition : '', $join['expr']->on))
                : '';
            preg_match_all('/\b(\w+)\.(\w+)\s*=\s*(\w+)\.(\w+)\b/', $onClause, $pairs, PREG_SET_ORDER);
            foreach ($pairs as [, $aliasA, $columnA, $aliasB, $columnB]) {
                $aliasA = strtolower($aliasA);
                $aliasB = strtolower($aliasB);
                if ($aliasA === $aliasB || !isset($tables[$aliasA], $tables[$aliasB])) {
                    continue;
                }

                $uniqueA = $this->isUniqueColumn($tables[$aliasA], $columnA);
                $uniqueB = $this->isUniqueColumn($tables[$aliasB], $columnB);
                $one = $uniqueA === $uniqueB ? null : ($uniqueA ? $aliasA : $aliasB);
                $edges[] = ['one' => $one, 'many' => $uniqueA ? $aliasB : $aliasA, 'a' => $aliasA, 'b' => $aliasB];
            }
        }

        return $edges;
    }

    /**
     * The column alone identifies a row: single-column primary key or unique field of a mapped table, or a column
     * named "id" on a table Doctrine does not map.
     */
    private function isUniqueColumn(string $table, string $column): bool
    {
        $metadata = $this->getMetadataMap()[$table] ?? null;
        if (null === $metadata) {
            return 'id' === strtolower($column);
        }

        $identifier = $metadata->getIdentifierColumnNames();
        if (1 === \count($identifier) && strtolower($identifier[0]) === strtolower($column)) {
            return true;
        }

        foreach ($metadata->fieldMappings as $mapping) {
            if (strtolower((string) MappingHelper::getString($mapping, 'columnName')) === strtolower($column)) {
                return true === MappingHelper::getBool($mapping, 'unique');
            }
        }

        return false;
    }

    /**
     * @param array{function: string, expression: string, alias: string, table: string, multiplier: string, distinct: bool} $finding
     */
    private function createAggregationWithInnerJoinIssue(
        string $aggregation,
        string $sql,
        array|object $query,
        bool $isProtected,
        array $finding,
    ): PerformanceIssue {
        $backtrace = $this->extractBacktrace($query);

        if ($isProtected) {
            $title = sprintf('%s with INNER JOIN Over Duplicated Rows', $aggregation);
            $description = sprintf(
                '%s counts each %s row once although the join with %s repeats it, so the result is correct. '
                . 'The database still builds and sorts the repeated rows first.',
                $finding['expression'],
                $finding['table'],
                $finding['multiplier'],
            );
            $severity = Severity::info();
        } else {
            $title = sprintf('%s with INNER JOIN May Cause Incorrect Results', $aggregation);
            // The second sentence is whole for each aggregation, so it can be translated.
            $description = sprintf(
                '%s reads %s, and the join with %s repeats each %s row once per matching %s row.',
                $finding['expression'],
                $finding['table'],
                $finding['multiplier'],
                $finding['table'],
                $finding['multiplier'],
            ) . ' ' . match ($aggregation) {
                'COUNT' => 'The count is inflated by those repeated rows.',
                'AVG' => 'The average is weighted is inflated by those repeated rows.',
                default => 'The sum is inflated by those repeated rows.',
            };
            $severity = Severity::warning();
        }

        $issueData = new IssueData(
            type: IssueType::AGGREGATION_WITH_INNER_JOIN->value,
            title: $title,
            description: $description,
            severity: $severity,
            suggestion: $this->createAggregationWithInnerJoinSuggestion($aggregation, $sql, $isProtected),
            queries: [],
            backtrace: $backtrace,
        );

        return new PerformanceIssue($issueData->toArray());
    }

    /**
     * Extract backtrace from query data.
     * @return array<int, array<string, mixed>>|null
     */

    /**
     * Create suggestion for LEFT JOIN with NOT NULL.
     */
    private function createLeftJoinWithNotNullSuggestion(
        string $tableName,
        string $field,
    ): mixed {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/left_join_with_not_null',
            context: [
                'entity' => $tableName,
                'field' => $field,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::info(),
                title: 'Replace LEFT JOIN with INNER JOIN',
                tags: ['code-quality', 'join', 'optimization'],
            ),
        );
    }

    /**
     * Create suggestion for aggregation with INNER JOIN.
     */
    private function createAggregationWithInnerJoinSuggestion(
        string $aggregation,
        string $sql,
        bool $isProtected,
    ): mixed {
        $tags = $isProtected
            ? ['performance', 'join', 'aggregation', 'optimization']
            : ['bug', 'join', 'aggregation', 'count'];

        $severity = $isProtected ? Severity::info() : Severity::warning();

        $title = $isProtected
            ? sprintf('Optimize %s query to avoid row duplication', $aggregation)
            : sprintf('Fix %s with INNER JOIN row duplication', $aggregation);

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/aggregation_with_inner_join',
            context: [
                'aggregation' => $aggregation,
                'original_query' => $sql,
                'is_protected' => $isProtected,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $severity,
                title: $title,
                tags: $tags,
            ),
        );
    }

    /**
     * Build metadata map (cached for performance).
     * @return array<string, ClassMetadata>
     */
    private function getMetadataMap(): array
    {
        if (null !== $this->metadataMapCache) {
            return $this->metadataMapCache;
        }

        $map = [];
        $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        foreach ($allMetadata as $metadata) {
            $tableName = $metadata->getTableName();
            $map[$tableName] = $metadata;
        }

        $this->metadataMapCache = $map;

        return $map;
    }
}
