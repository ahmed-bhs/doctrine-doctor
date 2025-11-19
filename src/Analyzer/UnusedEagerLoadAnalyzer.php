<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Webmozart\Assert\Assert;

/**
 * Detects unused eager loading (JOIN FETCH) that wastes memory and bandwidth.
 *
 * Inspired by nplusone's EagerListener which tracks loaded vs accessed instances.
 * This static analyzer detects:
 * - JOINs where the joined table's alias is never used in SELECT/WHERE/ORDER BY
 * - Over-eager loading with multiple JOINs causing data duplication
 * - Redundant eager loading patterns
 *
 * This is a CRITICAL performance issue often overlooked:
 * - Wastes memory by loading unused data
 * - Causes row duplication with collection JOINs
 * - Increases network bandwidth
 * - Slower hydration time
 *
 * Example:
 * ```php
 * // BAD: Loads author but never uses it
 * SELECT a FROM Article a JOIN a.author u
 * foreach ($articles as $article) {
 *     echo $article->getTitle(); // Never calls $article->getAuthor()
 * }
 * ```
 */
class UnusedEagerLoadAnalyzer implements AnalyzerInterface
{
    /** @var int Threshold for detecting over-eager loading */
    private const MULTIPLE_JOINS_THRESHOLD = 3;

    /** @var string Issue type identifier */
    private const ISSUE_TYPE = 'unused_eager_load';

    private SqlStructureExtractor $sqlExtractor;

    public function __construct(
        /**
         * @readonly
         */
        private IssueFactoryInterface $issueFactory,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
        ?SqlStructureExtractor $sqlExtractor = null,
    ) {
        $this->sqlExtractor = $sqlExtractor ?? new SqlStructureExtractor();
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                Assert::isIterable($queryDataCollection->toArray(), '$queryDataCollection must be iterable');

                foreach ($queryDataCollection->toArray() as $queryData) {
                    $sql = $queryData->sql;

                    // Only analyze SELECT queries with JOINs
                    if (!$this->sqlExtractor->isSelectQuery($sql) || !$this->sqlExtractor->hasJoin($sql)) {
                        continue;
                    }

                    $issues = $this->detectUnusedEagerLoads($sql, $queryData->backtrace);
                    foreach ($issues as $issueData) {
                        yield $this->issueFactory->create($issueData);
                    }
                }
            },
        );
    }

    /**
     * Detects unused eager loading patterns.
     *
     * @param array<int, array<string, mixed>>|null $backtrace
     *
     * @return array<IssueData>
     */
    private function detectUnusedEagerLoads(string $sql, ?array $backtrace): array
    {
        $issues = [];

        // Pattern 1: Detect JOINs with unused aliases
        $unusedJoins = $this->detectUnusedJoinAliases($sql);
        if (\count($unusedJoins) > 0) {
            $issues[] = $this->createUnusedJoinIssue($sql, $unusedJoins, $backtrace);
        }

        // Pattern 2: Detect over-eager loading (too many JOINs)
        $overEagerIssue = $this->detectOverEagerLoading($sql, $backtrace);
        if (null !== $overEagerIssue) {
            $issues[] = $overEagerIssue;
        }

        return $issues;
    }

    /**
     * Detects JOINs where the joined table's alias is never used.
     *
     * @return array<int, array{type: string, table: string, alias: ?string}>
     */
    private function detectUnusedJoinAliases(string $sql): array
    {
        $joins = $this->sqlExtractor->extractJoins($sql);
        $unusedJoins = [];

        foreach ($joins as $join) {
            $alias = $join['alias'];
            if (null === $alias) {
                continue; // Skip JOINs without alias
            }

            // Build JOIN expression to exclude from alias usage check
            // This prevents counting the alias usage in its own ON clause
            // Note: $alias is guaranteed to be non-null here due to the check above
            $joinExpression = $join['type'] . ' JOIN ' . $join['table'] . ' ' . $alias;

            // Check if alias is used in SELECT, WHERE, ORDER BY, GROUP BY, or HAVING
            // Pass joinExpression so isAliasUsedInQuery ignores this JOIN's ON clause
            $isUsed = $this->sqlExtractor->isAliasUsedInQuery($sql, $alias, $joinExpression);

            if (!$isUsed) {
                $unusedJoins[] = $join;
            }
        }

        return $unusedJoins;
    }

    /**
     * Detects over-eager loading with too many JOINs.
     */
    private function detectOverEagerLoading(string $sql, ?array $backtrace): ?IssueData
    {
        $joinCount = $this->sqlExtractor->countJoins($sql);

        if ($joinCount < self::MULTIPLE_JOINS_THRESHOLD) {
            return null; // Not enough JOINs to be considered over-eager
        }

        // Over-eager loading: Multiple JOINs that likely cause data duplication
        $description = DescriptionHighlighter::highlight(
            'Over-eager loading detected: {count} JOINs in a single query. This can cause significant data duplication and memory waste, especially with collection relationships (OneToMany/ManyToMany).',
            ['count' => $joinCount],
        );

        $description .= "\n\nEach collection JOIN multiplies the result rows. With {$joinCount} JOINs, you may be loading the same parent entity data hundreds or thousands of times.";
        $description .= "\n\nConsider using separate queries, batch fetching, or loading only the relations you actually need.";

        return new IssueData(
            type: self::ISSUE_TYPE,
            title: "Over-Eager Loading: {$joinCount} JOINs in Single Query",
            description: $description,
            severity: $this->calculateOverEagerSeverity($joinCount),
            suggestion: $this->createOverEagerSuggestion($joinCount),
            queries: [],
            backtrace: $backtrace,
        );
    }

    /**
     * @param array<int, array{type: string, table: string, alias: ?string}> $unusedJoins
     * @param array<int, array<string, mixed>>|null                          $backtrace
     */
    private function createUnusedJoinIssue(string $sql, array $unusedJoins, ?array $backtrace): IssueData
    {
        $joinCount = \count($unusedJoins);
        $tables = array_map(fn ($join) => $join['table'], $unusedJoins);
        $aliases = array_map(fn ($join) => $join['alias'] ?? 'unknown', $unusedJoins);

        $description = DescriptionHighlighter::highlight(
            'Unused eager loading detected: {count} JOIN(s) fetching data that is never used. Tables: {tables} (aliases: {aliases})',
            [
                'count' => $joinCount,
                'tables' => implode(', ', $tables),
                'aliases' => implode(', ', $aliases),
            ],
        );

        $description .= "\n\nThese JOINs load data into memory but the joined entities are never accessed in your code.";
        $description .= "\nThis wastes:";
        $description .= "\n- Memory (loading unused entities)";
        $description .= "\n- Bandwidth (transferring unused data)";
        $description .= "\n- CPU (hydrating unused objects)";
        $description .= "\n- Time (larger result sets to process)";

        return new IssueData(
            type: self::ISSUE_TYPE,
            title: "Unused Eager Load: {$joinCount} JOIN(s) Never Accessed",
            description: $description,
            severity: $this->calculateSeverity($joinCount),
            suggestion: $this->createSuggestion($unusedJoins),
            queries: [],
            backtrace: $backtrace,
        );
    }

    /**
     * @param array<int, array{type: string, table: string, alias: ?string}> $unusedJoins
     */
    private function createSuggestion(array $unusedJoins): ?SuggestionInterface
    {
        $tables = array_map(fn ($join) => $join['table'], $unusedJoins);
        $aliases = array_map(fn ($join) => $join['alias'] ?? 'unknown', $unusedJoins);

        return $this->suggestionFactory->createUnusedEagerLoad(
            unusedTables: $tables,
            unusedAliases: $aliases,
        );
    }

    private function createOverEagerSuggestion(int $joinCount): ?SuggestionInterface
    {
        return $this->suggestionFactory->createOverEagerLoading(
            joinCount: $joinCount,
        );
    }

    private function calculateSeverity(int $unusedJoinCount): Severity
    {
        // More unused JOINs = more wasted resources
        if ($unusedJoinCount >= 3) {
            return Severity::critical();
        }

        if ($unusedJoinCount >= 2) {
            return Severity::warning();
        }

        return Severity::info();
    }

    private function calculateOverEagerSeverity(int $joinCount): Severity
    {
        // Many JOINs can cause exponential data duplication with collections
        if ($joinCount >= 5) {
            return Severity::critical(); // Likely severe performance issue
        }

        if ($joinCount >= 4) {
            return Severity::warning();
        }

        return Severity::info();
    }
}
