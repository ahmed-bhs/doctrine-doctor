<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\CollectionJoinDetector;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\Mapping\ClassMetadata;
use Webmozart\Assert\Assert;

class JoinOptimizationAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    public function __construct(
        private readonly CollectionJoinDetector $collectionJoinDetector,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly SqlStructureExtractor $sqlExtractor,
        private readonly int $maxJoinsRecommended = 5,
        private readonly int $maxJoinsCritical = 8,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {

                $metadataMap = $this->collectionJoinDetector->buildMetadataMap();
                $seenIssues  = [];

                Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $query) {
                    $context = $this->extractQueryContext($query);

                    if (!$this->hasJoin($context['sql'])) {
                        continue;
                    }

                    $joins = $this->extractJoins($context['sql']);

                    if ([] === $joins) {
                        continue;
                    }

                    yield from $this->analyzeQueryJoins($context, $joins, $metadataMap, $seenIssues, $query);
                }
            },
        );
    }

    public function getName(): string
    {
        return 'JOIN Optimization Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects suboptimal JOIN usage: LEFT JOIN on NOT NULL, too many JOINs, unused JOINs';
    }

    /**
     * @return array{sql: string, executionTime: float}
     */
    private function extractQueryContext(array|object $query): array
    {
        $sql = is_array($query) ? ($query['sql'] ?? '') : (is_object($query) && property_exists($query, 'sql') ? $query->sql : '');

        $executionTime = 0.0;
        if (is_array($query)) {
            $executionTime = $query['executionMS'] ?? 0;
        } elseif (is_object($query) && property_exists($query, 'executionTime') && null !== $query->executionTime) {
            $executionTime = $query->executionTime->inMilliseconds();
        }

        return [
            'sql'           => $sql,
            'executionTime' => $executionTime,
        ];
    }

    /**
     * @param array{sql: string, executionTime: float} $context
     * @param array<mixed> $joins
     * @param array<mixed> $metadataMap
     * @param array<string, bool> $seenIssues
     * @return \Generator<PerformanceIssue>
     */
    private function analyzeQueryJoins(array $context, array $joins, array $metadataMap, array &$seenIssues, array|object $query): \Generator
    {
        yield from $this->checkAndYieldTooManyJoins($context, $joins, $seenIssues, $query);

        yield from $this->checkAndYieldJoinIssues($context, $joins, $metadataMap, $seenIssues, $query);
    }

    /**
     * @param array{sql: string, executionTime: float} $context
     * @param array<mixed> $joins
     * @param array<string, bool> $seenIssues
     * @return \Generator<PerformanceIssue>
     */
    private function checkAndYieldTooManyJoins(array $context, array $joins, array &$seenIssues, array|object $query): \Generator
    {
        $tooManyJoins = $this->checkTooManyJoins($context['sql'], $joins, $context['executionTime'], $query);

        if ($tooManyJoins instanceof PerformanceIssue) {
            $key = $this->buildIssueKey($tooManyJoins);

            if (!isset($seenIssues[$key])) {
                $seenIssues[$key] = true;
                yield $tooManyJoins;
            }
        }
    }

    /**
     * @param array{sql: string, executionTime: float} $context
     * @param array<mixed> $joins
     * @param array<mixed> $metadataMap
     * @param array<string, bool> $seenIssues
     * @return \Generator<PerformanceIssue>
     */
    private function checkAndYieldJoinIssues(array $context, array $joins, array $metadataMap, array &$seenIssues, array|object $query): \Generator
    {
        Assert::isIterable($joins, '$joins must be iterable');

        foreach ($joins as $join) {
            yield from $this->checkAndYieldSuboptimalJoin($join, $metadataMap, $context, $seenIssues, $query);
            yield from $this->checkAndYieldUnusedJoin($join, $context['sql'], $seenIssues, $query);
        }
    }

    /**
     * @param array<mixed> $join
     * @param array<mixed> $metadataMap
     * @param array{sql: string, executionTime: float} $context
     * @param array<string, bool> $seenIssues
     * @return \Generator<PerformanceIssue>
     */
    private function checkAndYieldSuboptimalJoin(array $join, array $metadataMap, array $context, array &$seenIssues, array|object $query): \Generator
    {
        $suboptimalJoin = $this->checkSuboptimalJoinType($join, $metadataMap, $context['sql'], $context['executionTime'], $query);

        if ($suboptimalJoin instanceof PerformanceIssue) {
            $key = $this->buildIssueKey($suboptimalJoin);

            if (!isset($seenIssues[$key])) {
                $seenIssues[$key] = true;
                yield $suboptimalJoin;
            }
        }
    }

    /**
     * @param array<mixed> $join
     * @param array<string, bool> $seenIssues
     * @return \Generator<PerformanceIssue>
     */
    private function checkAndYieldUnusedJoin(array $join, string $sql, array &$seenIssues, array|object $query): \Generator
    {
        $unusedJoin = $this->checkUnusedJoin($join, $sql, $query);

        if ($unusedJoin instanceof PerformanceIssue) {
            $key = $this->buildIssueKey($unusedJoin);

            if (!isset($seenIssues[$key])) {
                $seenIssues[$key] = true;
                yield $unusedJoin;
            }
        }
    }

    private function buildIssueKey(PerformanceIssue $performanceIssue): string
    {
        $data = $performanceIssue->getData();
        $table = $data['table'] ?? '';
        Assert::string($table, 'Table must be a string');

        return $performanceIssue->getTitle() . '|' . $table;
    }

    private function hasJoin(string $sql): bool
    {
        return $this->sqlExtractor->hasJoin($sql);
    }

    private function extractJoins(string $sql): array
    {
        $parsedJoins = $this->sqlExtractor->extractJoins($sql);

        $joins = [];

        foreach ($parsedJoins as $join) {
            $tableName = $join['table'];
            $alias = $join['alias'];

            if (null === $alias) {
                if (1 === preg_match('/\b' . preg_quote($tableName, '/') . '\.\w+/i', $sql)) {
                    $alias = $tableName;
                }
            }

            $joins[] = [
                'type'       => $join['type'],
                'table'      => $tableName,
                'alias'      => $alias,
                'full_match' => $join['type'] . ' JOIN ' . $tableName . (null !== $join['alias'] ? ' ' . $join['alias'] : ''),
            ];
        }

        return $joins;
    }

    private function checkTooManyJoins(string $sql, array $joins, float $executionTime, array|object $query): ?PerformanceIssue
    {
        $joinCount = count($joins);

        if ($joinCount <= $this->maxJoinsRecommended) {
            return null;
        }

        $severity = $joinCount > $this->maxJoinsCritical ? 'critical' : 'warning';

        $backtrace = $this->extractBacktrace($query);

        $performanceIssue = new PerformanceIssue([
            'query'           => $this->truncateQuery($sql),
            'join_count'      => $joinCount,
            'max_recommended' => $this->maxJoinsRecommended,
            'execution_time'  => $executionTime,
            'queries'         => [$query],
            'backtrace'       => $backtrace,
        ]);

        $performanceIssue->setSeverity($severity);
        $performanceIssue->setTitle(sprintf('Too Many JOINs in Single Query (%d tables)', $joinCount));
        $performanceIssue->setMessage(
            sprintf('Query contains %d JOINs (recommended: ', $joinCount) . $this->maxJoinsRecommended . ' max). ' .
            'This can severely impact performance. Consider splitting into multiple queries or using subqueries.',
        );
        $performanceIssue->setSuggestion($this->createTooManyJoinsSuggestion($joinCount, $sql));

        return $performanceIssue;
    }

    /**
     * @param array<string, ClassMetadata> $metadataMap
     */
    private function checkSuboptimalJoinType(
        array $join,
        array $metadataMap,
        string $sql,
        float $executionTime,
        array|object $query,
    ): ?PerformanceIssue {
        $tableName = $join['table'];
        $joinType  = $join['type'];

        $metadata = $metadataMap[$tableName] ?? null;

        if (null === $metadata) {
            return null;
        }

        if ('LEFT' === $joinType) {
            $fromTable = $this->collectionJoinDetector->extractFromTable($sql, $metadataMap);

            if (null === $fromTable) {
                return null;
            }

            $isCollection = $this->collectionJoinDetector->isCollectionJoin($join, $metadataMap, $sql, $fromTable);

            if ($isCollection) {
                return null;
            }

            $isNullable = $this->isJoinNullable($join, $sql, $metadata);

            if (false === $isNullable) {
                return $this->createLeftJoinOnNotNullIssue($join, $metadata, $sql, $executionTime, $query);
            }
        }

        return null;
    }

    private function isJoinNullable(array $join, string $sql, ClassMetadata $classMetadata): ?bool
    {
        $onClause = $this->sqlExtractor->extractJoinOnClause($sql, (string) $join['full_match']);

        if (null === $onClause) {
            return null;
        }

        preg_match_all('/(?:\w+\.)?(\w+)\s*=/', $onClause, $matches);
        $columnsInOnClause = array_map(strtolower(...), $matches[1]);

        if ([] === $columnsInOnClause) {
            return null;
        }

        $bestMatch = null;
        $bestMatchCount = 0;

        foreach ($classMetadata->getAssociationMappings() as $associationMapping) {
            if (!isset($associationMapping['joinColumns'])) {
                continue;
            }

            Assert::isIterable($associationMapping['joinColumns'], 'joinColumns must be iterable');

            $joinColumns = $associationMapping['joinColumns'];

            if (!is_array($joinColumns)) {
                $joinColumns = iterator_to_array($joinColumns);
            }

            $matchedColumns = 0;
            $allNullable = true;

            foreach ($joinColumns as $joinColumn) {
                $columnName = $joinColumn['name'] ?? null;

                if (null === $columnName) {
                    continue;
                }

                $columnNameLower = strtolower((string) $columnName);

                if (in_array($columnNameLower, $columnsInOnClause, true)) {
                    ++$matchedColumns;

                    $isNullable = $joinColumn['nullable'] ?? true;
                    if (false === $isNullable) {
                        $allNullable = false;
                    }
                }
            }

            if ($matchedColumns === count($joinColumns) && $matchedColumns > $bestMatchCount) {
                $bestMatchCount = $matchedColumns;
                $bestMatch = $allNullable;
            }
        }

        return $bestMatch;
    }

    private function createLeftJoinOnNotNullIssue(
        array $join,
        ClassMetadata $classMetadata,
        string $sql,
        float $executionTime,
        array|object $query,
    ): PerformanceIssue {
        $entityClass = $classMetadata->getName();
        $tableName   = $join['table'];

        $performanceIssue = new PerformanceIssue([
            'query'          => $this->truncateQuery($sql),
            'join_type'      => 'LEFT',
            'table'          => $tableName,
            'entity'         => $entityClass,
            'execution_time' => $executionTime,
            'queries'        => [$query],
            'backtrace'      => $this->createEntityBacktrace($classMetadata),
        ]);

        $performanceIssue->setSeverity('critical');
        $performanceIssue->setTitle('Suboptimal LEFT JOIN on NOT NULL Relation');
        $performanceIssue->setMessage(
            sprintf("Query uses LEFT JOIN on table '%s' which appears to have a NOT NULL foreign key. ", $tableName) .
            'Using INNER JOIN instead would be 20-30% faster.',
        );
        $performanceIssue->setSuggestion($this->createLeftJoinSuggestion($join, $entityClass, $tableName));

        return $performanceIssue;
    }

    private function checkUnusedJoin(array $join, string $sql, array|object $query): ?PerformanceIssue
    {
        $alias = $join['alias'];

        if (null === $alias) {
            return null;
        }

        if (!$this->sqlExtractor->isAliasUsedInQuery($sql, $alias, (string) $join['full_match'])) {
            $backtrace = $this->extractBacktrace($query);

            $performanceIssue = new PerformanceIssue([
                'query'     => $this->truncateQuery($sql),
                'join_type' => $join['type'],
                'table'     => $join['table'],
                'alias'     => $alias,
                'queries'   => [$query],
                'backtrace' => $backtrace,
            ]);

            $performanceIssue->setSeverity('warning');
            $performanceIssue->setTitle('Unused JOIN Detected');
            $performanceIssue->setMessage(
                sprintf("Query performs %s JOIN on table '%s' (alias '%s') but never uses it. ", $join['type'], $join['table'], $alias) .
                'Remove this JOIN to improve performance.',
            );
            $performanceIssue->setSuggestion($this->createUnusedJoinSuggestion($join));

            return $performanceIssue;
        }

        return null;
    }

    private function createTooManyJoinsSuggestion(int $joinCount, string $sql): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/join_too_many',
            context: [
                'join_count' => $joinCount,
                'sql'        => $this->truncateQuery($sql),
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $joinCount > 8 ? Severity::critical() : Severity::warning(),
                title: sprintf('Too Many JOINs in Single Query (%d tables)', $joinCount),
                tags: ['performance', 'join', 'query'],
            ),
        );
    }

    private function createLeftJoinSuggestion(array $join, string $entityClass, string $tableName): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/join_left_on_not_null',
            context: [
                'table'  => $tableName,
                'alias'  => $join['alias'],
                'entity' => $entityClass,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::critical(),
                title: 'Suboptimal LEFT JOIN on NOT NULL Relation',
                tags: ['performance', 'join', 'optimization'],
            ),
        );
    }

    private function createUnusedJoinSuggestion(array $join): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/join_unused',
            context: [
                'type'  => $join['type'],
                'table' => $join['table'],
                'alias' => $join['alias'],
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: 'Unused JOIN Detected',
                tags: ['performance', 'join', 'unused'],
            ),
        );
    }

    private function truncateQuery(string $sql, int $maxLength = 200): string
    {
        if (strlen($sql) <= $maxLength) {
            return $sql;
        }

        return substr($sql, 0, $maxLength) . '...';
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function createEntityBacktrace(ClassMetadata $classMetadata): ?array
    {
        try {
            $reflectionClass = $classMetadata->getReflectionClass();
            $fileName        = $reflectionClass->getFileName();
            $startLine       = $reflectionClass->getStartLine();

            if (false === $fileName || false === $startLine) {
                return null;
            }

            return [
                [
                    'file'     => $fileName,
                    'line'     => $startLine,
                    'class'    => $classMetadata->getName(),
                    'function' => '__construct',
                    'type'     => '::',
                ],
            ];
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function extractBacktrace(array|object $query): ?array
    {
        if (is_array($query)) {
            return $query['backtrace'] ?? null;
        }

        return is_object($query) && property_exists($query, 'backtrace') ? ($query->backtrace ?? null) : null;
    }
}
