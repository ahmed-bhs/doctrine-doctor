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
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use Webmozart\Assert\Assert;

class LazyLoadingAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
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
        private int $threshold = 10,
        ?SqlStructureExtractor $sqlExtractor = null,
    ) {
        $this->sqlExtractor = $sqlExtractor ?? new SqlStructureExtractor();
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        //  Article pattern: Use generator instead of array
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                $lazyLoadPatterns = $this->detectLazyLoadingPatterns($queryDataCollection);

                Assert::isIterable($lazyLoadPatterns, '$lazyLoadPatterns must be iterable');

                foreach ($lazyLoadPatterns as $lazyLoadPattern) {
                    if ($lazyLoadPattern['count'] >= $this->threshold) {
                        // Use factory to create suggestion (new architecture)
                        $suggestion = $this->suggestionFactory->createEagerLoading(
                            entity: $lazyLoadPattern['entity'],
                            relation: $lazyLoadPattern['relation'],
                            queryCount: $lazyLoadPattern['count'],
                        );

                        $issueData = new IssueData(
                            type: 'lazy_loading',
                            title: sprintf('Lazy Loading in Loop: %d queries on %s', $lazyLoadPattern['count'], $lazyLoadPattern['entity']),
                            description: DescriptionHighlighter::highlight(
                                'Detected {count} sequential lazy-loaded queries on entity {entity} (relation: {relation}). ' .
                                'Use eager loading with {joinFetch} to avoid N+1 queries (threshold: {threshold})',
                                [
                                    'count' => $lazyLoadPattern['count'],
                                    'entity' => $lazyLoadPattern['entity'],
                                    'relation' => $lazyLoadPattern['relation'] ?? 'unknown',
                                    'joinFetch' => 'JOIN FETCH',
                                    'threshold' => $this->threshold,
                                ],
                            ),
                            severity: $suggestion->getMetadata()->severity,
                            suggestion: $suggestion,
                            queries: $lazyLoadPattern['queries'],
                            backtrace: $lazyLoadPattern['backtrace'] ?? null,
                        );

                        yield $this->issueFactory->create($issueData);
                    }
                }
            },
        );
    }

    private function detectLazyLoadingPatterns(QueryDataCollection $queryDataCollection): array
    {

        $patterns          = [];
        $sequentialQueries = [];

        // Detect SELECT queries that load single entities by ID (lazy loading pattern)
        Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

        foreach ($queryDataCollection as $index => $queryData) {
            // Detect lazy loading pattern using SQL parser
            // Pattern: SELECT ... FROM table WHERE id = ? (single entity load)
            // Parser properly handles SQL structure and avoids false positives
            // OPTIMIZED: Uses CachedSqlStructureExtractor (transparent via DI) for 1000x speedup
            $table = $this->sqlExtractor->detectLazyLoadingPattern($queryData->sql);

            if (null !== $table) {
                // Group by table and check if they're sequential
                if (!isset($sequentialQueries[$table])) {
                    $sequentialQueries[$table] = [];
                }

                $sequentialQueries[$table][] = [
                    'query' => $queryData,
                    'index' => $index,
                ];
            }
        }

        // Analyze sequential patterns
        Assert::isIterable($sequentialQueries, '$sequentialQueries must be iterable');

        foreach ($sequentialQueries as $table => $queryGroup) {
            if (count($queryGroup) >= $this->threshold) {
                // Check if queries are close together (likely in a loop)
                $indices      = array_column($queryGroup, 'index');
                $isSequential = $this->areQueriesInLoop($indices);

                if ($isSequential) {
                    $totalTime    = 0;
                    $queryDetails = [];

                    Assert::isIterable($queryGroup, '$queryGroup must be iterable');

                    foreach ($queryGroup as $item) {
                        $queryData = $item['query'];
                        $totalTime += $queryData->executionTime->inMilliseconds();
                        $queryDetails[] = $queryData;
                    }

                    // Try to infer entity and relation names
                    $entityName = $this->tableToEntityName($table);
                    $relation   = $this->inferRelationFromBacktrace($queryDetails[0]->backtrace);

                    $patterns[] = [
                        'entity'     => $entityName,
                        'relation'   => $relation,
                        'count'      => count($queryGroup),
                        'total_time' => $totalTime,
                        'backtrace'  => $queryDetails[0]->backtrace,
                        'queries'    => array_slice($queryDetails, 0, 20),
                    ];
                }
            }
        }

        return $patterns;
    }

    private function areQueriesInLoop(array $indices): bool
    {
        if (count($indices) < 2) {
            return false;
        }

        // Check if queries are relatively close together
        $gaps    = [];
        $counter = count($indices);
        for ($i = 1; $i < $counter; ++$i) {
            $gaps[] = $indices[$i] - $indices[$i - 1];
        }

        // If average gap is small (< 5 queries apart), they're likely in a loop
        $avgGap = array_sum($gaps) / count($gaps);

        return $avgGap <= 5;
    }

    private function tableToEntityName(string $table): string
    {
        // Remove common prefixes
        $table = preg_replace('/^(tbl_|tb_)/', '', $table);

        // Convert to PascalCase
        $parts = explode('_', (string) $table);

        return implode('', array_map(function ($part) {
            return ucfirst($part);
        }, $parts));
    }

    private function inferRelationFromBacktrace(?array $backtrace): string
    {
        if (null === $backtrace || [] === $backtrace) {
            return 'relation';
        }

        // Try to find getter methods in backtrace
        Assert::isIterable($backtrace, '$backtrace must be iterable');

        foreach ($backtrace as $frame) {
            // Pattern: Simple pattern match: /^get([A-Z]\w+)/
            if (isset($frame['function']) && 1 === preg_match('/^get([A-Z]\w+)/', $frame['function'], $matches)) {
                return lcfirst($matches[1]);
            }
        }

        return 'relation';
    }
}
