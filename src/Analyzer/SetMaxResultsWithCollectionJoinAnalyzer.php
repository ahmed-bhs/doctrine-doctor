<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

/**
 * Detects queries using LIMIT (from setMaxResults) with collection joins.
 * This is a critical anti-pattern because:
 * - LIMIT applies to SQL rows, not to entities
 * - When joining collections (OneToMany, ManyToMany), one entity can span multiple rows
 * - This leads to partially hydrated collections (missing data)
 * - Data loss is silent and hard to detect
 * Example problem:
 * ```php
 * $qb->select('pet')
 *    ->addSelect('pictures')
 *    ->from(Pet::class, 'pet')
 *    ->leftJoin('pet.pictures', 'pictures')
 *    ->setMaxResults(1);
 * ```
 * If Pet has 4 pictures, only 1 picture will be loaded due to LIMIT 1.
 * Solution: Use Doctrine's Paginator
 * ```php
 * $paginator = new Paginator($query, $fetchJoinCollection = true);
 * ```
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/current/tutorials/pagination.html
 */
class SetMaxResultsWithCollectionJoinAnalyzer implements AnalyzerInterface
{
    public function __construct(
        /**
         * @readonly
         */
        private IssueFactoryInterface $issueFactory,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        // Article pattern: Use generator instead of array
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $queryData) {
                    if (!$queryData->isSelect()) {
                        continue;
                    }

                    if ($this->hasLimitWithFetchJoin($queryData->sql)) {
                        yield $this->createIssue($queryData);
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'setMaxResults with Collection Join Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects queries using setMaxResults() with collection joins, which causes partial collection hydration';
    }

    /**
     * Detect if query has LIMIT with a fetch-joined collection.
     * Heuristics:
     * 1. Query must have LIMIT clause
     * 2. Query must have JOIN (LEFT JOIN or INNER JOIN)
     * 3. Query must SELECT columns from joined table (fetch join)
     */
    private function hasLimitWithFetchJoin(string $sql): bool
    {
        // Normalize SQL for easier matching
        $sql = preg_replace('/\s+/', ' ', strtoupper($sql));

        // Must have LIMIT
        if (1 !== preg_match('/\sLIMIT\s+\d+/', (string) $sql)) {
            return false;
        }

        // Must have JOIN
        if (1 !== preg_match('/\s(?:LEFT\s+JOIN|INNER\s+JOIN|JOIN)\s+/i', (string) $sql)) {
            return false;
        }

        // Check if it's a fetch join (selecting from joined table)
        // Pattern: SELECT t0.col1, t1.col2 ... FROM table1 t0 JOIN table2 t1
        // If we select from both t0 and t1, it's a fetch join

        // Extract SELECT clause
        if (1 !== preg_match('/^SELECT\s+(.*?)\s+FROM/i', (string) $sql, $selectMatches)) {
            return false;
        }

        $selectClause = $selectMatches[1];

        // Extract table aliases from SELECT
        // Pattern: t0_.column, t1_.column, etc.
        preg_match_all('/(\w+)_\.\w+/', $selectClause, $aliasMatches);

        if ([] === $aliasMatches[1]) {
            return false;
        }

        $uniqueAliases = array_unique($aliasMatches[1]);

        // If selecting from multiple table aliases, it's likely a fetch join
        // (selecting both parent entity and joined collection)
        return count($uniqueAliases) >= 2;
    }

    private function createIssue(QueryData $queryData): IssueInterface
    {
        $tables    = $this->extractTableNames($queryData->sql);
        $mainTable = $tables[0] ?? 'entity';

        $issueData = new IssueData(
            type: 'setMaxResults_with_collection_join',
            title: 'setMaxResults() with Collection Join Detected',
            description: 'Query uses LIMIT with a fetch-joined collection. This causes LIMIT to apply to SQL rows ' .
            'instead of entities, resulting in partially hydrated collections (silent data loss). ' .
            "

Problem: If the main entity has multiple related items, only some will be loaded. " .
            'For example, if a Pet has 4 pictures and you use setMaxResults(1), only 1 picture ' .
            "will be loaded instead of 4.

" .
            "Solution: Use Doctrine's Paginator which executes 2 queries to properly handle collection joins.",
            severity: Severity::critical(),
            suggestion: $this->createSuggestion($mainTable),
            queries: [$queryData],
            backtrace: $queryData->backtrace,
        );

        return $this->issueFactory->create($issueData);
    }

    private function createSuggestion(string $entityHint): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'setMaxResults_with_collection_join',
            context: [
                'entity_hint' => $entityHint,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::critical(),
                title: 'Use Doctrine Paginator for Collection Joins with Limits',
                tags: ['critical', 'data-loss', 'pagination', 'collections', 'anti-pattern'],
            ),
        );
    }

    /**
     * Extract table names from SQL for better context in error messages.
     * @return string[]
     */
    private function extractTableNames(string $sql): array
    {

        $tables = [];

        // Pattern: FROM table_name alias
        if (1 === preg_match('/FROM\s+(\w+)\s+(\w+)/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        // Pattern: JOIN table_name alias
        preg_match_all('/JOIN\s+(\w+)\s+(\w+)/i', $sql, $joinMatches);

        if ([] !== $joinMatches[1]) {
            return array_merge($tables, $joinMatches[1]);
        }

        return $tables;
    }
}
