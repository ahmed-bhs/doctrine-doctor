<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Webmozart\Assert\Assert;

class HydrationAnalyzer implements AnalyzerInterface
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
        private int $rowThreshold = 99,
        /**
         * @readonly
         */
        private int $criticalThreshold = 999,
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
                Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

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
        // Use SQL parser to extract LIMIT value
        // Supports various formats: LIMIT 100, LIMIT 10,100, LIMIT 100 OFFSET 10
        return $this->sqlExtractor->getLimitValue($sql);
    }

    private function generateSuggestion(int $rowCount): SuggestionInterface
    {
        $suggestionMetadata = new SuggestionMetadata(
            type: SuggestionType::performance(),
            severity: $rowCount > $this->criticalThreshold ? Severity::critical() : Severity::warning(),
            title: sprintf('Excessive Hydration: %d rows', $rowCount),
            tags: ['performance', 'hydration', 'optimization'],
        );

        return $this->suggestionFactory->createFromTemplate(
            'Performance/excessive_hydration',
            [
                'row_count' => $rowCount,
                'threshold' => $this->rowThreshold,
                'critical_threshold' => $this->criticalThreshold,
            ],
            $suggestionMetadata,
        );
    }
}
