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
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Psr\Log\LoggerInterface;

class FindAllAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactoryInterface $suggestionFactory,
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
                foreach ($queryDataCollection as $queryData) {
                    if ($this->isFindAllPattern($queryData->sql)) {
                        $rowCount = $queryData->rowCount;

                        if (null !== $rowCount && $rowCount <= $this->threshold) {
                            continue;
                        }

                        $isKnownCount = null !== $rowCount;
                        $severity = $isKnownCount ? Severity::warning() : Severity::info();

                        $title = $isKnownCount
                            ? sprintf('Unrestricted Query: %d rows selected without WHERE or LIMIT', $rowCount)
                            : 'Unrestricted Query: no WHERE or LIMIT clause, row count unknown';

                        $description = $isKnownCount
                            ? DescriptionHighlighter::highlight(
                                'A query without a {where} or {limit} clause returned {count} rows. ' .
                                'This may come from findAll(), a custom repository method, or a hand-built ' .
                                'QueryBuilder. Consider adding pagination or filters (threshold: {threshold})',
                                [
                                    'where' => 'WHERE',
                                    'limit' => 'LIMIT',
                                    'count' => $rowCount,
                                    'threshold' => $this->threshold,
                                ],
                            )
                            : DescriptionHighlighter::highlight(
                                'A query without a {where} or {limit} clause was detected, but the actual row ' .
                                'count could not be measured. This may come from findAll(), a custom repository ' .
                                'method, or a hand-built QueryBuilder, and may load an unbounded number of rows ' .
                                'into memory. Verify the table size and consider adding pagination or filters.',
                                [
                                    'where' => 'WHERE',
                                    'limit' => 'LIMIT',
                                ],
                            );

                        $suggestion = $this->suggestionFactory->createFromTemplate(
                            templateName: 'Performance/pagination',
                            context: [
                                'method' => 'this query',
                                'result_count' => $rowCount,
                            ],
                            suggestionMetadata: new SuggestionMetadata(
                                type: SuggestionType::performance(),
                                severity: $severity,
                                title: $title,
                                tags: ['performance', 'pagination', 'memory'],
                            ),
                        );

                        $issueData = new IssueData(
                            type: IssueType::FIND_ALL->value,
                            title: $title,
                            description: $description,
                            severity: $severity,
                            suggestion: $suggestion,
                            queries: [$queryData],
                            backtrace: $queryData->backtrace,
                        );

                        yield $this->issueFactory->create($issueData);
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

        if (preg_match('/^\s*SELECT\s+(DISTINCT\s+)?(COUNT|MAX|MIN|SUM|AVG)\s*\(/i', $sql) > 0) {
            return false;
        }

        if (preg_match('/^\s*SELECT\s+(DISTINCT\s+)?EXISTS\s*\(/i', $sql) > 0) {
            return false;
        }

        if (preg_match('/\b(GROUP\s+BY|HAVING)\b/i', $sql) > 0) {
            return false;
        }

        if (preg_match('/\b(COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $sql) > 0) {
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

}
