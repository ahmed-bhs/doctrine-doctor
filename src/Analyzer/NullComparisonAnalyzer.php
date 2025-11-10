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
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\CodeQualityIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

/**
 * Detects incorrect NULL comparisons using = or != operators.
 * In SQL, comparing to NULL with = or != does not work as expected.
 * You must use IS NULL or IS NOT NULL.
 * Example:
 * BAD:
 *   WHERE bonus = NULL       -- Always returns no rows!
 *   WHERE bonus != NULL      -- Always returns no rows!
 * GOOD:
 *   WHERE bonus IS NULL
 *   WHERE bonus IS NOT NULL
 */
class NullComparisonAnalyzer implements AnalyzerInterface
{
    /**
     * Pattern to detect incorrect NULL comparisons.
     * Matches: = NULL, != NULL, <> NULL
     */
    private const NULL_COMPARISON_PATTERN = '/(\w+(?:\.\w+)?)\s*(=|!=|<>)\s*NULL\b/i';

    public function __construct(
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                $seenComparisons = [];

                assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $query) {
                    $sql = $this->extractSQL($query);
                    if ('' === $sql) {
                        continue;
                    }

                    if ('0' === $sql) {
                        continue;
                    }

                    // Find all incorrect NULL comparisons
                    if (preg_match_all(self::NULL_COMPARISON_PATTERN, $sql, $matches, PREG_SET_ORDER) >= 1) {
                        assert(is_iterable($matches), '$matches must be iterable');

                        foreach ($matches as $match) {
                            $fullMatch = $match[0];
                            $field     = $match[1];
                            $operator  = $match[2];

                            // Deduplicate
                            $key = $field . $operator . 'NULL';
                            if (isset($seenComparisons[$key])) {
                                continue;
                            }

                            $seenComparisons[$key] = true;

                            yield $this->createNullComparisonIssue($field, $operator, $fullMatch, $query);
                        }
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'NULL Comparison Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects incorrect NULL comparisons using = or != operators instead of IS NULL / IS NOT NULL';
    }

    /**
     * Extract SQL from query data.
     */
    private function extractSQL(array|object $query): string
    {
        if (is_array($query)) {
            return $query['sql'] ?? '';
        }

        return is_object($query) && property_exists($query, 'sql') ? ($query->sql ?? '') : '';
    }

    /**
     * Create issue for incorrect NULL comparison.
     */
    private function createNullComparisonIssue(
        string $field,
        string $operator,
        string $fullMatch,
        array|object $query,
    ): CodeQualityIssue {
        $backtrace     = $this->extractBacktrace($query);
        $correctSyntax = $this->getCorrectSyntax($field, $operator);

        $issueData = new IssueData(
            type: 'incorrect_null_comparison',
            title: 'Incorrect NULL Comparison',
            description: sprintf(
                "Found '%s' in query. Comparing to NULL with '%s' does not work as expected in SQL. " .
                "This will ALWAYS return no rows. Use '%s' instead.",
                $fullMatch,
                $operator,
                $correctSyntax,
            ),
            severity: Severity::critical(),
            suggestion: $this->createNullComparisonSuggestion($field, $operator, $fullMatch, $correctSyntax),
            queries: [],
            backtrace: $backtrace,
        );

        return new CodeQualityIssue($issueData->toArray());
    }

    /**
     * Get the correct NULL comparison syntax.
     */
    private function getCorrectSyntax(string $field, string $operator): string
    {
        if ('=' === $operator) {
            return sprintf('%s IS NULL', $field);
        }

        return sprintf('%s IS NOT NULL', $field);
    }

    /**
     * Extract backtrace from query data.
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
     * Create suggestion for fixing NULL comparison.
     */
    private function createNullComparisonSuggestion(
        string $field,
        string $operator,
        string $fullMatch,
        string $correctSyntax,
    ): mixed {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'null_comparison',
            context: [
                'incorrect' => $fullMatch,
                'correct'   => $correctSyntax,
                'field'     => $field,
                'operator'  => $operator,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::codeQuality(),
                severity: Severity::critical(),
                title: 'Use IS NULL / IS NOT NULL for NULL comparisons',
                tags: ['sql', 'null', 'comparison', 'logic-error'],
            ),
        );
    }
}
