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
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;

class HydrationAnalyzer implements AnalyzerInterface
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
        private int $rowThreshold = 99,
        /**
         * @readonly
         */
        private int $criticalThreshold = 999,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        //  Article pattern: Use generator instead of array
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $queryData) {
                    // Try to get row count from query data first
                    $rowCount = $queryData->rowCount;

                    // If not available, estimate from SQL LIMIT clause
                    if (null === $rowCount) {
                        $rowCount = $this->estimateRowCountFromSql($queryData->sql);

                        // Skip if we can't estimate
                        if (null === $rowCount) {
                            continue;
                        }
                    }

                    // If many rows returned/estimated
                    if ($rowCount > $this->rowThreshold) {
                        // Any query returning more than threshold is a hydration concern
                        $issueData = new IssueData(
                            type: 'hydration',
                            title: sprintf('Excessive Hydration: %d rows', $rowCount),
                            description: DescriptionHighlighter::highlight(
                                'Query {action} {count} rows which may cause significant hydration overhead (threshold: {threshold})',
                                [
                                    'action' => null === $queryData->rowCount ? 'fetches up to' : 'returned',
                                    'count' => $rowCount,
                                    'threshold' => $this->rowThreshold,
                                ],
                            ),
                            severity: $rowCount > $this->criticalThreshold ? Severity::critical() : Severity::warning(),
                            suggestion: $this->generateSuggestion($rowCount),
                            queries: [$queryData],
                            backtrace: $queryData->backtrace,
                        );

                        yield $this->issueFactory->create($issueData);
                    }
                }
            },
        );
    }

    /**
     * Estimate row count from SQL LIMIT clause.
     * Note: This is an estimation based on LIMIT. The actual row count
     * may be less if the table has fewer rows.
     * @return int|null The LIMIT value if found, null otherwise
     */
    private function estimateRowCountFromSql(string $sql): ?int
    {
        // Match LIMIT clause (supports various formats)
        // LIMIT 100
        // LIMIT 10, 100 (offset, limit)
        // LIMIT 100 OFFSET 10
        if (1 === preg_match('/LIMIT\s+(?:(\d+)\s*,\s*)?(\d+)(?:\s+OFFSET\s+\d+)?/i', $sql, $matches)) {
            // The second capture group always contains the limit value
            // (whether it's "LIMIT 10" or "LIMIT 5, 10")
            return (int) $matches[2];
        }

        return null;
    }

    private function generateSuggestion(int $rowCount): SuggestionInterface
    {
        $suggestions = [];

        if ($rowCount > $this->criticalThreshold) {
            $suggestions[] = sprintf('// CRITICAL: %d rows is excessive!', $rowCount);
            $suggestions[] = '// Use pagination or filtering to limit result set';
            $suggestions[] = '// Example: ->setMaxResults(100) or add WHERE conditions';
        }

        $suggestions[] = '// Option 1: Array hydration (faster for read-only)';
        $suggestions[] = '$result = $query->getResult(Query::HYDRATE_ARRAY);';
        $suggestions[] = '';
        $suggestions[] = '// Option 2: DTO hydration (best performance + type-safe)';
        $suggestions[] = 'SELECT NEW App\DTO\MyDTO(e.id, e.name) FROM Entity e';
        $suggestions[] = '';
        $suggestions[] = '// Option 3: PARTIAL objects (select only needed fields)';
        $suggestions[] = 'SELECT PARTIAL e.{id, name} FROM Entity e';
        $suggestions[] = '';
        $suggestions[] = '// Option 4: Pagination (limit results)';
        $suggestions[] = '$query->setFirstResult($offset)->setMaxResults($limit);';

        return $this->suggestionFactory->createQueryOptimization(
            code: implode("
", $suggestions),
            optimization: sprintf(
                'Query returned %d rows which may cause significant hydration overhead in PHP. ' .
                'Consider limiting results or using lighter hydration modes.',
                $rowCount,
            ),
            executionTime: 0.0,
            threshold: $this->rowThreshold,
        );
    }
}
