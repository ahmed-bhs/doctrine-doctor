<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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

readonly class NPlusOneSqlAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private IssueFactoryInterface $issueFactory,
        private SuggestionFactoryInterface $suggestionFactory,
        private int $threshold = 3,
        private SqlStructureExtractor $sqlExtractor = new SqlStructureExtractor(),
        private SqlQueryNormalizer $normalizer = new SqlQueryNormalizer(),
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        // ORM lazy loading is NPlusOneAnalyzer's case, fixed with a fetch join, not with hand-written SQL.
        $selects = $queryDataCollection->filter(
            fn (QueryData $query): bool => $this->sqlExtractor->isSelectQuery($query->sql) && !$this->isGeneratedByOrm($query),
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

                if ($this->allIdentical($queries)) {
                    yield $this->createIdenticalQueryIssue($queries, $count, $totalMs, $severity);

                    continue;
                }

                $suggestion = $this->suggestionFactory->createFromTemplate(
                    templateName: 'Integrity/code_suggestion',
                    context: [
                        'description' => sprintf(
                            'The same query ran %d times, once per value. Fetch all values in one query with an array parameter, then group the rows in PHP.',
                            $count,
                        ),
                        'code' => $this->batchedQueryExample($first->sql),
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
                        'The same SQL ran %d times in one request, once per value: %s. A loop in DBAL code issues one query per iteration.',
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

    /**
     * The same SQL with the same parameters: nothing varies between the runs, so no IN (...) can replace them.
     *
     * @param array<int, QueryData> $queries
     */
    private function allIdentical(array $queries): bool
    {
        // A placeholder without recorded values could hide a different value on each run: not provably identical.
        if ([] === $queries[0]->params && 1 === preg_match('/\\?|:\\w+/', $queries[0]->sql)) {
            return false;
        }

        $first = $queries[0]->sql . json_encode($queries[0]->params);

        return array_all($queries, static fn (QueryData $query): bool => $query->sql . json_encode($query->params) === $first);
    }

    /**
     * @param array<int, QueryData> $queries
     */
    private function createIdenticalQueryIssue(array $queries, int $count, float $totalMs, Severity $severity): \AhmedBhs\DoctrineDoctor\Issue\IssueInterface
    {
        $sql = $queries[0]->sql;

        return $this->issueFactory->create(new IssueData(
            type: IssueType::IDENTICAL_QUERY_REPEATED->value,
            title: sprintf('Identical Query Repeated: %d times in one request (%.2fms total)', $count, $totalMs),
            description: sprintf(
                'The same SQL with the same parameters ran %d times in one request: %s. Each run returns the same rows. '
                . 'Doctrine keeps no query result between calls (the identity map only avoids loading an entity twice by its id): '
                . 'read it once and keep the result for the request.',
                $count,
                mb_substr($sql, 0, 160),
            ),
            severity: $severity,
            suggestion: $this->suggestionFactory->createFromTemplate(
                templateName: 'Integrity/code_suggestion',
                context: [
                    'description' => sprintf('The same query ran %d times with the same parameters. Keep its result in the service for the request.', $count),
                    'code' => "private ?array \$rows = null;\n\n"
                        . "public function rows(): array\n{\n"
                        . '    return $this->rows ??= $this->connection->fetchAllAssociative(' . var_export($sql, true) . ");\n}\n",
                ],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::performance(),
                    severity: $severity,
                    title: 'Keep the result for the request',
                    tags: ['performance', 'repeated-query', 'dbal'],
                ),
            ),
            queries: $queries,
            backtrace: $queries[0]->backtrace,
        ));
    }

    /**
     * Doctrine aliases every selected column as <column>_<n> (id_0, name_1),
     * which hand-written SQL does not do; a Doctrine\ORM frame in the backtrace,
     * when collected, confirms it.
     */
    private function isGeneratedByOrm(QueryData $query): bool
    {
        if (1 === preg_match('/^\s*SELECT\s.+?\sAS\s+\w+_\d+\s*(?:,|FROM\b)/is', $query->sql)) {
            return true;
        }
        return array_any($query->backtrace ?? [], fn ($frame) => str_starts_with((string) ($frame['class'] ?? ''), 'Doctrine\\ORM\\'));
    }

    private function batchedQueryExample(string $sql): string
    {
        $batchedSql = preg_replace('/(\b[\w.]+)\s*=\s*\?/', '$1 IN (?)', $sql, 1) ?? $sql;

        return "use Doctrine\\DBAL\\ArrayParameterType;\n\n"
            . "// One query for all values instead of one per value\n"
            . "\$rows = \$connection->fetchAllAssociative(\n"
            . '    ' . var_export($batchedSql, true) . ",\n"
            . "    [\$values],\n"
            . "    [ArrayParameterType::INTEGER], // or ArrayParameterType::STRING\n"
            . ");\n";
    }
}
