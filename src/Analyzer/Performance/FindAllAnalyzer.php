<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

class FindAllAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
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
        /**
         * @readonly
         */
        private int $threshold = 99,
        /**
         * @readonly
         */
        private ?LoggerInterface $logger = null,
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
                Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

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
        if (!str_starts_with($normalized, 'SELECT')) {
            return false;
        }

        // Ignore aggregate queries - they don't load entities into memory
        // COUNT, MAX, MIN, SUM, AVG return a single value, not entity collections
        if (preg_match('/SELECT\s+(COUNT|MAX|MIN|SUM|AVG)\s*\(/i', $sql)) {
            return false;
        }

        // Ignore EXISTS queries
        if (preg_match('/SELECT\s+EXISTS\s*\(/i', $sql)) {
            return false;
        }

        try {
            // Use SQL parser to properly detect WHERE/LIMIT (avoids false positives from comments/strings)
            $hasWhere = !empty($this->sqlExtractor->extractWhereColumns($sql));
            $hasLimit = $this->sqlExtractor->hasLimit($sql);

            // Pattern: SELECT without WHERE and without LIMIT
            return !$hasWhere && !$hasLimit;
        } catch (\Throwable $e) {
            // Fallback to simple string detection if parser fails
            $this->logger?->warning('[FindAllAnalyzer] Parser failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            $sqlUpper = strtoupper($sql);
            $hasWhere = str_contains($sqlUpper, ' WHERE ');
            $hasLimit = str_contains($sqlUpper, ' LIMIT ');

            return !$hasWhere && !$hasLimit;
        }
    }

    private function estimateRowCount(QueryData $queryData): int
    {
        // PRIORITY 1: Use actual row count from query result if available
        if (null !== $queryData->rowCount) {
            return $queryData->rowCount;
        }

        // FALLBACK: For findAll() detection, we assume the query returns many rows
        // since there's no WHERE/LIMIT clause. We use a high estimate to trigger
        // the warning. In a real scenario without rowCount, it's better to be
        // conservative and warn about potential issues.
        return 999; // Assume potentially large result set
    }
}
