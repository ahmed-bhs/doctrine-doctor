<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
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
    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly int $threshold = 99,
        private readonly ?LoggerInterface $logger = null,
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
                Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $queryData) {
                    if ($this->isFindAllPattern($queryData->sql)) {
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

        if (!str_starts_with($normalized, 'SELECT')) {
            return false;
        }

        if (preg_match('/SELECT\s+(COUNT|MAX|MIN|SUM|AVG)\s*\(/i', $sql) > 0) {
            return false;
        }

        if (preg_match('/SELECT\s+EXISTS\s*\(/i', $sql) > 0) {
            return false;
        }

        try {
            $hasWhere = !empty($this->sqlExtractor->extractWhereColumns($sql));
            $hasLimit = $this->sqlExtractor->hasLimit($sql);

            return !$hasWhere && !$hasLimit;
        } catch (\Throwable $e) {
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
        if (null !== $queryData->rowCount) {
            return $queryData->rowCount;
        }

        return 999; // Assume potentially large result set
    }
}
