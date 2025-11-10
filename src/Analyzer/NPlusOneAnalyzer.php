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
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;

class NPlusOneAnalyzer implements AnalyzerInterface
{
    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
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
        private int $threshold = 5,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        //  Use collection's groupByPattern method - business logic centralized
        $queryGroups = $queryDataCollection->groupByPattern(
            fn (string $sql): string => $this->normalizeQuery($sql),
        );

        //  Use generator for memory efficiency
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryGroups) {
                assert(is_iterable($queryGroups), '$queryGroups must be iterable');

                foreach ($queryGroups as $pattern => $group) {
                    if ($group->count() >= $this->threshold) {
                        $totalTime  = $group->totalExecutionTime();
                        $groupArray = $group->toArray();

                        $issueData = new IssueData(
                            type: 'n_plus_one',
                            title: sprintf('N+1 Query Detected: %d queries', $group->count()),
                            description: DescriptionHighlighter::highlight(
                                'Found {count} similar queries with total execution time of {time}ms. Pattern: {pattern}',
                                [
                                    'count' => $group->count(),
                                    'time' => sprintf('%.2f', $totalTime),
                                    'pattern' => $pattern,
                                ],
                            ),
                            severity: $this->calculateSeverity($group->count(), $totalTime),
                            suggestion: $this->generateSuggestion($groupArray),
                            queries: $groupArray,
                            backtrace: $group->first()?->backtrace,
                        );

                        yield $this->issueFactory->create($issueData);
                    }
                }
            },
        );
    }

    private function normalizeQuery(string $sql): string
    {
        // Normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', trim($sql));

        // Replace string literals (careful with quotes)
        $normalized = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', (string) $normalized);

        // Replace numeric literals only if not part of identifiers
        // Avoid replacing numbers in table/column names
        $normalized = preg_replace('/\b(\d+)\b/', '?', (string) $normalized);

        // Normalize IN clauses
        $normalized = preg_replace('/IN\s*\([^)]+\)/i', 'IN (?)', (string) $normalized);

        // Normalize = placeholders
        $normalized = preg_replace('/=\s*\?/', '= ?', (string) $normalized);

        return strtoupper((string) $normalized);
    }

    /**
     * @param QueryData[] $queryGroup
     */
    private function generateSuggestion(array $queryGroup): ?SuggestionInterface
    {
        $sql = $queryGroup[0]->sql;

        // Pattern 1: WHERE t0.xxx_id = ?
        if (1 === preg_match('/FROM\s+(\w+)\s+\w+\s+WHERE\s+\w+\.(\w+)_id\s*=/i', $sql, $matches)) {
            $entity   = $this->tableToEntity($matches[1]);
            $relation = $this->underscoreToCamelCase($matches[2]);

            return $this->suggestionFactory->createJoinFetch(
                entity: $entity,
                relation: $relation,
                queryCount: 1,
            );
        }

        // Pattern 2: JOIN with condition on ID
        if (1 === preg_match('/JOIN\s+(\w+)\s+\w+\s+ON\s+\w+\.id\s*=\s*\w+\.(\w+)_id/i', $sql, $matches)) {
            $entity   = $this->tableToEntity($matches[1]);
            $relation = $this->underscoreToCamelCase($matches[2]);

            return $this->suggestionFactory->createJoinFetch(
                entity: $entity,
                relation: $relation,
                queryCount: 1,
            );
        }

        // Pattern 3: Simple SELECT with foreign key
        if (1 === preg_match('/SELECT\s+.*?\s+FROM\s+(\w+).*?WHERE.*?(\w+)_id\s*=/i', $sql, $matches)) {
            $entity   = $this->tableToEntity($matches[1]);
            $relation = $this->underscoreToCamelCase($matches[2]);

            return $this->suggestionFactory->createJoinFetch(
                entity: $entity,
                relation: $relation,
                queryCount: 1,
            );
        }

        return null;
    }

    private function calculateSeverity(int $count, float $totalTime): Severity
    {
        // Critical if many queries OR significant total time
        if ($count > 20 || $totalTime > 1000) {
            return Severity::critical();
        }

        // Critical if many queries OR significant total time
        if ($count > 10 || $totalTime > 500) {
            return Severity::warning();
        }

        return Severity::info();
    }

    private function tableToEntity(string $table): string
    {
        try {
            $metadatas = $this->entityManager->getMetadataFactory()->getAllMetadata();

            assert(is_iterable($metadatas), '$metadatas must be iterable');

            foreach ($metadatas as $metadata) {
                if ($metadata->getTableName() === $table) {
                    return $metadata->getName();
                }
            }
        } catch (\Throwable) {
            // If metadata loading fails, fallback to simple conversion
        }

        // Fallback: convert table name to entity name (e.g., user_profile -> UserProfile)
        return $this->tableToClassName($table);
    }

    private function tableToClassName(string $table): string
    {
        // Remove common prefixes
        $table = preg_replace('/^(tbl_|app_)/', '', $table);

        // Convert snake_case to PascalCase
        return str_replace('_', '', ucwords((string) $table, '_'));
    }

    private function underscoreToCamelCase(string $string): string
    {
        return lcfirst(str_replace('_', '', ucwords($string, '_')));
    }
}
