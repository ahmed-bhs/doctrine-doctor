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
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;

class FindAllAnalyzer implements AnalyzerInterface
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
        private int $threshold = 99,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $queryData) {
                    // Detect SELECT * FROM table without WHERE or LIMIT
                    if ($this->isFindAllPattern($queryData->sql)) {
                        // Count approximate rows that would be returned
                        $rowCount = $this->estimateRowCount($queryData);

                        if ($rowCount > $this->threshold) {
                            $suggestion = $this->suggestionFactory->createPagination(
                                method: 'findAll',
                                resultCount: $rowCount,
                            );

                            $issueData = new IssueData(
                                type: 'find_all',
                                title: sprintf('Unpaginated Query: findAll() returned %d rows', $rowCount),
                                description: DescriptionHighlighter::highlight(
                                    'Query without {where} or {limit} clause returned approximately {count} rows. ' .
                                    'Consider adding pagination or filters (threshold: {threshold})',
                                    [
                                        'where' => 'WHERE',
                                        'limit' => 'LIMIT',
                                        'count' => $rowCount,
                                        'threshold' => $this->threshold,
                                    ],
                                ),
                                severity: $suggestion->getMetadata()->severity,
                                suggestion: $suggestion,
                                queries: [$queryData],
                                backtrace: $queryData->backtrace,
                            );

                            yield $this->issueFactory->create($issueData);
                        }
                    }
                }
            },
        );
    }

    private function isFindAllPattern(string $sql): bool
    {
        $normalized = strtoupper(trim($sql));

        // Must be a SELECT query
        if (1 !== preg_match('/^SELECT/', $normalized)) {
            return false;
        }

        // Has no WHERE clause (or only simple joins)
        $hasWhere = preg_match('/\sWHERE\s/i', $sql);

        // Has no LIMIT clause
        $hasLimit = preg_match('/\sLIMIT\s/i', $sql);

        // Pattern: SELECT without WHERE and without LIMIT
        return 1 !== $hasWhere && 1 !== $hasLimit;
    }

    private function estimateRowCount(QueryData $queryData): int
    {
        // Try to extract table name
        $sql = $queryData->sql;

        // Pattern: FROM table_name
        // Note: Full analysis would require row count checking
        // Currently flagging all findAll() as potentially problematic
        if (1 === preg_match('/FROM\s+(\w+)/i', $sql)) {
            // If we have access to query result info, use it
            // Otherwise, assume it's potentially large
            // In a real scenario, you might want to query the table to get row count
            // But for now, we'll flag it as potentially problematic

            // You could extend this to actually count rows if needed
            return 999; // Placeholder - assume potentially large
        }

        return 0;
    }
}
