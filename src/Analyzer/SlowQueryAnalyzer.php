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
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use Webmozart\Assert\Assert;

class SlowQueryAnalyzer implements AnalyzerInterface
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
        private int $threshold = 100,
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
                assert(is_iterable($slowQueries), '$slowQueries must be iterable');

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
     * Analyze query to provide optimization hints.
     * This is a simple helper that identifies query patterns.
     */
    private function analyzeQueryOptimizations(string $sql): string
    {
        $optimizations = [];

        if (1 === preg_match('/(SELECT.*FROM.*WHERE.*\(.*SELECT)/i', $sql)) {
            $optimizations[] = 'Subquery detected - consider rewriting as JOIN';
        }

        if (1 === preg_match('/ORDER BY/i', $sql)) {
            $optimizations[] = 'Ensure ORDER BY columns are indexed';
        }

        if (1 === preg_match('/GROUP BY/i', $sql)) {
            $optimizations[] = 'Ensure GROUP BY columns are indexed';
        }

        if (1 === preg_match('/LIKE\s+[\'"]%/i', $sql)) {
            $optimizations[] = 'Leading wildcard LIKE detected - cannot use index efficiently';
        }

        if (1 === preg_match('/SELECT DISTINCT/i', $sql)) {
            $optimizations[] = 'DISTINCT operation can be expensive';
        }

        return [] === $optimizations
            ? 'Review query structure and add appropriate indexes.'
            : implode('. ', $optimizations) . '.';
    }
}
