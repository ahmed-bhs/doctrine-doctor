<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlQueryNormalizer;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

final class NPlusOneSqlAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly int $threshold = 3,
        private readonly SqlStructureExtractor $sqlExtractor = new SqlStructureExtractor(),
        private readonly SqlQueryNormalizer $normalizer = new SqlQueryNormalizer(),
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        $selects = $queryDataCollection->filter(
            fn (QueryData $q): bool => $this->sqlExtractor->isSelectQuery($q->sql),
        );

        $groups = $selects->groupByPattern(
            fn (string $sql): string => $this->normalizer->normalizeQuery($sql),
        );

        return IssueCollection::fromGenerator(function () use ($groups) {
            foreach ($groups as $pattern => $queryGroup) {
                $queries = $queryGroup->toArray();
                $count = count($queries);
                if ($count < $this->threshold) {
                    continue;
                }

                $first = $queries[0];
                $totalMs = 0.0;
                foreach ($queries as $q) {
                    $totalMs += $q->executionTime->inMilliseconds();
                }

                $severity = match (true) {
                    $count >= 20 => Severity::critical(),
                    $count >= 10 => Severity::warning(),
                    default => Severity::info(),
                };

                $suggestion = $this->suggestionFactory->createFromTemplate(
                    templateName: 'Performance/query_optimization',
                    context: [
                        'code' => $pattern,
                        'optimization' => sprintf(
                            'Detected %d similar SQL queries (DBAL). Batch with: SELECT ... WHERE id IN (?, ?, ...).',
                            $count,
                        ),
                        'execution_time' => $totalMs,
                        'threshold' => $this->threshold,
                    ],
                    suggestionMetadata: new SuggestionMetadata(
                        type: SuggestionType::performance(),
                        severity: $severity,
                        title: sprintf('N+1 SQL pattern: %d repeated queries', $count),
                        tags: ['performance', 'n+1', 'dbal'],
                    ),
                );

                $issueData = new IssueData(
                    type: IssueType::N_PLUS_ONE->value,
                    title: sprintf('N+1 SQL pattern (DBAL): %d similar queries (%.2fms total)', $count, $totalMs),
                    description: sprintf(
                        'The same SQL pattern was executed %d times within one request. Likely an N+1 loop in DBAL code. Pattern: %s',
                        $count,
                        $pattern,
                    ),
                    severity: $severity,
                    suggestion: $suggestion,
                    queries: $queries,
                    backtrace: $first->backtrace,
                );

                yield $this->issueFactory->create($issueData);
            }
        });
    }
}
