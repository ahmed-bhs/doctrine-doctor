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
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

class NotInSubqueryAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    public function __construct(
        private readonly SuggestionFactoryInterface $suggestionFactory,
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
                $seenIssues = [];

                foreach ($queryDataCollection as $query) {
                    $sql = $this->extractSQL($query);
                    if ('' === $sql || '0' === $sql) {
                        continue;
                    }

                    if (!$this->sqlExtractor->isSelectQuery($sql)) {
                        continue;
                    }

                    $column = $this->extractNotInSubqueryColumn($sql);
                    if (null === $column) {
                        continue;
                    }

                    $key = md5($this->sqlExtractor->normalizeQuery($sql));
                    if (isset($seenIssues[$key])) {
                        continue;
                    }

                    $seenIssues[$key] = true;

                    yield $this->createIssue($column, $sql, $query);
                }
            },
        );
    }

    public function getName(): string
    {
        return 'NOT IN Subquery Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects NOT IN (SELECT ...) patterns that silently return no rows when the subquery yields any NULL value';
    }

    private function extractSQL(array|object $query): string
    {
        if (is_array($query)) {
            return $query['sql'] ?? '';
        }

        return is_object($query) && property_exists($query, 'sql') ? ($query->sql ?? '') : '';
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function extractBacktrace(array|object $query): ?array
    {
        if (is_array($query)) {
            return $query['backtrace'] ?? null;
        }

        return is_object($query) && property_exists($query, 'backtrace') ? ($query->backtrace ?? null) : null;
    }

    /**
     * Detect pattern `<column> NOT IN ( SELECT ... )`. Returns the column reference or null.
     */
    private function extractNotInSubqueryColumn(string $sql): ?string
    {
        $pattern = '/([a-zA-Z_][\w]*(?:\.[a-zA-Z_][\w]*)?)\s+NOT\s+IN\s*\(\s*SELECT\b/i';
        if (1 !== preg_match($pattern, $sql, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function createIssue(string $column, string $sql, array|object $query): PerformanceIssue
    {
        $description = sprintf(
            'Query uses %s NOT IN (SELECT ...). If the subquery returns any NULL value, the whole NOT IN predicate ' .
            'evaluates to UNKNOWN and the outer query silently returns zero rows. This is a frequent source of bugs ' .
            'that disappear in tests but appear in production once a NULL slips into the related column. ' .
            'Rewrite with NOT EXISTS or LEFT JOIN ... IS NULL, which both handle NULLs correctly.',
            $column,
        );

        $issueData = new IssueData(
            type: 'not_in_subquery',
            title: sprintf('NOT IN Subquery on %s (NULL Pitfall)', $column),
            description: $description,
            severity: Severity::warning(),
            suggestion: $this->createSuggestion($column, $sql),
            queries: [$query], // @phpstan-ignore argument.type
            backtrace: $this->extractBacktrace($query),
        );

        return new PerformanceIssue($issueData->toArray());
    }

    private function createSuggestion(string $column, string $sql): mixed
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/not_in_subquery',
            context: [
                'column' => $column,
                'original_query' => $sql,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: 'Replace NOT IN (SELECT ...) with NOT EXISTS',
                tags: ['performance', 'correctness', 'null-handling', 'subquery'],
            ),
        );
    }
}
