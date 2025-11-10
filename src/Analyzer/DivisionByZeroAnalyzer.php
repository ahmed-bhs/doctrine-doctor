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
use AhmedBhs\DoctrineDoctor\Issue\SecurityIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

/**
 * Detects division operations that could result in division by zero errors.
 * Common issues:
 * 1. Direct division without zero check: revenue / quantity
 * 2. Division in SELECT without NULLIF or CASE
 * 3. Division in WHERE/HAVING clauses
 * Example:
 * BAD:
 *   SELECT revenue / quantity FROM sales;
 *   -- If quantity = 0, database error!
 * GOOD:
 *   SELECT revenue / NULLIF(quantity, 0) FROM sales;
 *   -- Returns NULL instead of error
 */
class DivisionByZeroAnalyzer implements AnalyzerInterface
{
    /**
     * Pattern to detect division operations in SQL/DQL.
     * Matches: field1 / field2, expression / field, etc.
     */
    private const DIVISION_PATTERN = '/(\w+(?:\.\w+)?)\s*\/\s*(\w+(?:\.\w+)?)/';

    /**
     * Pattern to detect if division is already protected.
     */
    private const PROTECTED_PATTERN = '/NULLIF|COALESCE|CASE\s+WHEN/i';

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
                $seenDivisions = [];

                assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $query) {
                    $sql = $this->extractSQL($query);
                    if ('' === $sql) {
                        continue;
                    }

                    if ('0' === $sql) {
                        continue;
                    }

                    // Skip if already protected
                    if ($this->isProtected($sql)) {
                        continue;
                    }

                    // Find all division operations
                    if (preg_match_all(self::DIVISION_PATTERN, $sql, $matches, PREG_SET_ORDER) >= 1) {
                        assert(is_iterable($matches), '$matches must be iterable');

                        foreach ($matches as $match) {
                            $fullMatch = $match[0];
                            $dividend  = $match[1];
                            $divisor   = $match[2];

                            // Deduplicate
                            $key = $dividend . '/' . $divisor;
                            if (isset($seenDivisions[$key])) {
                                continue;
                            }

                            $seenDivisions[$key] = true;

                            // Skip if divisor is a constant number (not zero)
                            if ($this->isNonZeroConstant($divisor)) {
                                continue;
                            }

                            yield $this->createDivisionByZeroIssue($dividend, $divisor, $fullMatch, $query);
                        }
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Division By Zero Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects division operations that could result in division by zero errors';
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
     * Check if the SQL already has protection against division by zero.
     */
    private function isProtected(string $sql): bool
    {
        return (bool) preg_match(self::PROTECTED_PATTERN, $sql);
    }

    /**
     * Check if divisor is a non-zero constant.
     */
    private function isNonZeroConstant(string $divisor): bool
    {
        // Check if it's a number
        if (is_numeric($divisor)) {
            return 0.0 !== (float) $divisor;
        }

        return false;
    }

    /**
     * Create issue for potential division by zero.
     */
    private function createDivisionByZeroIssue(
        string $dividend,
        string $divisor,
        string $fullMatch,
        array|object $query,
    ): SecurityIssue {
        $backtrace = $this->extractBacktrace($query);

        $issueData = new IssueData(
            type: 'division_by_zero',
            title: 'Potential Division By Zero Error',
            description: sprintf(
                "Division operation '%s' found in query. If '%s' is zero, this will cause a database error. " .
                "Use NULLIF(%s, 0) to safely handle zero values.",
                $fullMatch,
                $divisor,
                $divisor,
            ),
            severity: Severity::critical(),
            suggestion: $this->createDivisionByZeroSuggestion($dividend, $divisor, $fullMatch),
            queries: [],
            backtrace: $backtrace,
        );

        return new SecurityIssue($issueData->toArray());
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
     * Create suggestion for fixing division by zero.
     */
    private function createDivisionByZeroSuggestion(string $dividend, string $divisor, string $fullMatch): mixed
    {
        $safeDivision = sprintf('%s / NULLIF(%s, 0)', $dividend, $divisor);

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'division_by_zero',
            context: [
                'unsafe_division' => $fullMatch,
                'safe_division'   => $safeDivision,
                'dividend'        => $dividend,
                'divisor'         => $divisor,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::security(),
                severity: Severity::critical(),
                title: 'Use NULLIF to prevent division by zero',
                tags: ['security', 'division-by-zero', 'sql-error'],
            ),
        );
    }
}
