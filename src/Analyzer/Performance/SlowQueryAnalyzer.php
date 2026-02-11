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
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use Webmozart\Assert\Assert;

class SlowQueryAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly int $threshold = 100,
        private readonly SqlStructureExtractor $sqlExtractor = new SqlStructureExtractor(),
    ) {
        Assert::greaterThan($threshold, 0, 'Threshold must be positive, got %s');
        Assert::lessThan($threshold, 100000, 'Threshold seems unreasonably high (>100s), got %s ms');
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        //  Use collection's filterSlow method - business logic in collection
        $slowQueries = $queryDataCollection->filterSlow($this->threshold);

        //  Use generator for memory efficiency
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($slowQueries) {
                Assert::isIterable($slowQueries, '$slowQueries must be iterable');

                foreach ($slowQueries as $slowQuery) {
                    $executionTimeMs = $slowQuery->executionTime->inMilliseconds();

                    // Use factory to create suggestion (new architecture)
                    $suggestion = $this->suggestionFactory->createQueryOptimization(
                        code: $slowQuery->sql,
                        optimization: $this->analyzeQueryOptimizations($slowQuery->sql),
                        executionTime: $executionTimeMs,
                        threshold: $this->threshold,
                    );

                    $issueData = new IssueData(
                        type: 'slow_query',
                        title: sprintf('Slow Query: %.2fms', $executionTimeMs),
                        description: DescriptionHighlighter::highlight(
                            'Query execution time ({time}ms) exceeds threshold ({threshold}ms)',
                            [
                                'time' => sprintf('%.2f', $executionTimeMs),
                                'threshold' => $this->threshold,
                            ],
                        ),
                        severity: $suggestion->getMetadata()->severity,
                        suggestion: $suggestion,
                        queries: [$slowQuery],
                        backtrace: $slowQuery->backtrace,
                    );

                    yield $this->issueFactory->create($issueData);
                }
            },
        );
    }

    /**
     * Analyze query to provide optimization hints using SQL parser.
     * Identifies performance-impacting query patterns.
     */
    private function analyzeQueryOptimizations(string $sql): string
    {
        $optimizations = [];

        // Use SQL parser to detect subqueries
        if ($this->sqlExtractor->hasSubquery($sql)) {
            $optimizations[] = 'Subquery detected - consider rewriting as JOIN';
        }

        // Use SQL parser to detect ORDER BY clause
        if ($this->sqlExtractor->hasOrderBy($sql)) {
            $orderByColumns = $this->sqlExtractor->extractOrderByColumnNames($sql);
            if ([] !== $orderByColumns) {
                $optimizations[] = sprintf(
                    'Ensure ORDER BY columns are indexed: %s',
                    implode(', ', $orderByColumns),
                );
            } else {
                $optimizations[] = 'Ensure ORDER BY columns are indexed';
            }
        }

        // Use SQL parser to detect GROUP BY clause
        if ($this->sqlExtractor->hasGroupBy($sql)) {
            $groupByColumns = $this->sqlExtractor->extractGroupByColumns($sql);
            if ([] !== $groupByColumns) {
                $optimizations[] = sprintf(
                    'Ensure GROUP BY columns are indexed: %s',
                    implode(', ', $groupByColumns),
                );
            } else {
                $optimizations[] = 'Ensure GROUP BY columns are indexed';
            }
        }

        // Use SQL parser to detect leading wildcard LIKE
        if ($this->sqlExtractor->hasLeadingWildcardLike($sql)) {
            $optimizations[] = 'Leading wildcard LIKE detected - cannot use index efficiently';
        }

        // Use SQL parser to detect DISTINCT
        if ($this->sqlExtractor->hasDistinct($sql)) {
            $optimizations[] = 'DISTINCT operation can be expensive';
        }

        return [] === $optimizations
            ? 'Review query structure and add appropriate indexes.'
            : implode('. ', $optimizations) . '.';
    }
}
