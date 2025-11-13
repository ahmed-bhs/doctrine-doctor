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
        /**
         * @readonly
         */
        private SqlStructureExtractor $sqlExtractor,
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
     * 4. JOIN must not have constraints that guarantee single row per entity
     *    (e.g., locale filters, unique constraints)
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
        if (!$this->sqlExtractor->hasJoin($sql)) {
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
        if (count($uniqueAliases) < 2) {
            return false;
        }

        // Check for safe patterns that indicate single-row joins
        // These patterns prevent false positives for translation tables, etc.
        if ($this->hasSingleRowJoinConstraint($sql)) {
            return false;
        }

        return true;
    }

    /**
     * Detect if JOIN has constraints that guarantee at most one row per parent entity.
     * Common patterns:
     * - Translation tables with locale filter: AND (t1_.locale = ?)
     * - Unique constraints on joined table
     * - Primary key equality in JOIN condition
     *
     * These patterns are safe with LIMIT because they don't create row multiplication.
     */
    private function hasSingleRowJoinConstraint(string $sql): bool
    {
        // Pattern 1: Translation pattern with locale filter
        // Example: INNER JOIN translation t1_ ON ... AND (t1_.locale = ?)
        // This guarantees at most 1 translation row per entity
        if (1 === preg_match('/JOIN\s+\w+\s+\w+_\s+ON\s+.*?\s+AND\s+\(\w+_\.LOCALE\s*=\s*\?\)/i', $sql)) {
            return true;
        }

        // Pattern 2: Direct locale equality in ON clause
        // Example: JOIN translation t1_ ON t0_.id = t1_.entity_id AND t1_.locale = ?
        if (1 === preg_match('/ON\s+.*?\s+AND\s+\w+_\.LOCALE\s*=\s*\?/i', $sql)) {
            return true;
        }

        // Pattern 3: Join on unique identifier (primary key on joined table)
        // Example: JOIN user_profile t1_ ON t0_.id = t1_.user_id (where user_id is unique)
        // This is harder to detect reliably, so we look for common naming patterns
        // Note: This is a heuristic and may not catch all cases
        if (1 === preg_match('/JOIN\s+\w+\s+\w+_\s+ON\s+\w+_\.ID\s*=\s*\w+_\.(?:\w+_)?ID(?:\s+WHERE|\s+AND|\s+ORDER|\s+LIMIT|$)/i', $sql)) {
            // Only if there's exactly one JOIN condition (simple case)
            $joinConditions = substr_count($sql, ' AND ');
            if ($joinConditions === 0) {
                // This might be a one-to-one relationship, but we can't be sure
                // For now, we'll be conservative and flag it as potentially problematic
                // unless we see other indicators
            }
        }

        return false;
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
     * Uses SQL parser instead of regex for robust extraction.
     * @return string[]
     */
    private function extractTableNames(string $sql): array
    {
        $allTables = $this->sqlExtractor->extractAllTables($sql);

        // Extract just the table names
        return array_map(fn($table) => $table['table'], $allTables);
    }
}
