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

class FunctionOnPredicateColumnAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Functions that, when applied to a column in WHERE, defeat index usage.
     * Date functions (YEAR/MONTH/DAY/DATE/HOUR/MINUTE/SECOND) are intentionally excluded
     * because they are already handled by YearFunctionOptimizationAnalyzer.
     *
     * @var list<string>
     */
    private const array NON_SARGABLE_FUNCTIONS = [
        'LOWER',
        'UPPER',
        'COALESCE',
        'IFNULL',
        'ISNULL',
        'NULLIF',
        'CAST',
        'CONVERT',
        'TRIM',
        'LTRIM',
        'RTRIM',
        'SUBSTRING',
        'SUBSTR',
        'CONCAT',
        'ABS',
        'ROUND',
        'FLOOR',
        'CEIL',
        'CEILING',
    ];

    public function __construct(
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly SqlStructureExtractor $sqlExtractor = new SqlStructureExtractor(),
        private readonly float $minExecutionTimeMs = 10.0,
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

                    $executionTime = $this->extractExecutionTime($query);
                    if ($executionTime < $this->minExecutionTimeMs) {
                        continue;
                    }

                    foreach ($this->findNonSargableFunctions($sql) as $match) {
                        $key = md5(sprintf('%s|%s', $match['function'], $match['column']));
                        if (isset($seenIssues[$key])) {
                            continue;
                        }

                        $seenIssues[$key] = true;

                        yield $this->createIssue($match['function'], $match['column'], $executionTime, $sql, $query);
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Function On Predicate Column Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects functions wrapping filtered columns in WHERE (LOWER, COALESCE, CAST, ISNULL, etc.) that defeat index usage';
    }

    private function extractSQL(array|object $query): string
    {
        if (is_array($query)) {
            return $query['sql'] ?? '';
        }

        return is_object($query) && property_exists($query, 'sql') ? ($query->sql ?? '') : '';
    }

    private function extractExecutionTime(array|object $query): float
    {
        if (is_array($query)) {
            return (float) ($query['executionMS'] ?? 0);
        }

        return (is_object($query) && property_exists($query, 'executionTime')) ? ($query->executionTime?->inMilliseconds() ?? 0.0) : 0.0;
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
     * Find non-sargable function-on-column patterns inside the WHERE clause.
     * Returns matches with the SQL function name and the wrapped column reference.
     *
     * @return list<array{function: string, column: string}>
     */
    private function findNonSargableFunctions(string $sql): array
    {
        if (1 !== preg_match('/\bWHERE\b(.*?)(?:\bGROUP\s+BY\b|\bORDER\s+BY\b|\bLIMIT\b|\bHAVING\b|$)/is', $sql, $whereMatches)) {
            return [];
        }

        $whereClause = $whereMatches[1];
        $functionAlternation = implode('|', array_map('preg_quote', self::NON_SARGABLE_FUNCTIONS));

        $pattern = '/\b(' . $functionAlternation . ')\s*\(\s*([a-zA-Z_][\w]*(?:\.[a-zA-Z_][\w]*)?)/i';
        if (preg_match_all($pattern, $whereClause, $allMatches, PREG_SET_ORDER) < 1) {
            return [];
        }

        $matches = [];
        foreach ($allMatches as $match) {
            $matches[] = [
                'function' => strtoupper($match[1]),
                'column' => $match[2],
            ];
        }

        return $matches;
    }

    private function createIssue(
        string $function,
        string $column,
        float $executionTime,
        string $sql,
        array|object $query,
    ): PerformanceIssue {
        $severity = $executionTime > 100 ? Severity::critical() : Severity::warning();

        $description = sprintf(
            'WHERE clause wraps column %s in %s(), which prevents the database from using an index on that column ' .
            'and forces a full scan or function-based evaluation per row. Execution time: %.2fms. ' .
            'Rewrite the predicate so the column appears bare on one side of the comparison, ' .
            'or create a functional/expression index matching the function.',
            $column,
            $function,
            $executionTime,
        );

        $issueData = new IssueData(
            type: 'function_on_predicate_column',
            title: sprintf('%s() Wraps Predicate Column %s', $function, $column),
            description: $description,
            severity: $severity,
            suggestion: $this->createSuggestion($function, $column, $sql),
            queries: [$query], // @phpstan-ignore argument.type
            backtrace: $this->extractBacktrace($query),
        );

        return new PerformanceIssue($issueData->toArray());
    }

    private function createSuggestion(string $function, string $column, string $sql): mixed
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/function_on_predicate_column',
            context: [
                'function' => $function,
                'column' => $column,
                'original_query' => $sql,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: sprintf('Avoid wrapping %s in %s() inside WHERE', $column, $function),
                tags: ['performance', 'index', 'sargable', 'optimization'],
            ),
        );
    }
}
