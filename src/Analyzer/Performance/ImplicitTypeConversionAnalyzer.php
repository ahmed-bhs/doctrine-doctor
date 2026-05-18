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

/**
 * Detects predicates likely to trigger implicit type conversion in the database
 * engine, which typically disables index usage on the affected column.
 *
 * Heuristic, runtime-only:
 *  - column suffix `_id`, `id`, `_count`, `_qty`, `_amount`, `_price`, `_age`
 *    or column literally named `id`, `quantity`, `count`, `age`, `total`
 *    compared to a quoted string literal -> numeric column vs string.
 *  - column suffix `_at`, `_date`, `_time` or named `date`, `time`, `created_at`,
 *    `updated_at` compared to a bare integer literal -> date column vs integer.
 *
 * Compared against a placeholder (?, :param) the analyzer says nothing: the
 * actual bound type is invisible from the SQL text alone.
 */
class ImplicitTypeConversionAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Column-name heuristics for numeric columns.
     * @var list<string>
     */
    private const array NUMERIC_COLUMN_SUFFIXES = [
        '_id',
        '_count',
        '_qty',
        '_quantity',
        '_amount',
        '_price',
        '_age',
        '_total',
        '_size',
        '_length',
        '_width',
        '_height',
        '_weight',
    ];

    /**
     * @var list<string>
     */
    private const array NUMERIC_COLUMN_EXACT = [
        'id',
        'quantity',
        'count',
        'age',
        'total',
        'amount',
        'price',
        'size',
    ];

    /**
     * @var list<string>
     */
    private const array DATE_COLUMN_SUFFIXES = [
        '_at',
        '_date',
        '_time',
        '_timestamp',
    ];

    /**
     * @var list<string>
     */
    private const array DATE_COLUMN_EXACT = [
        'date',
        'time',
        'timestamp',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

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

                    foreach ($this->detectMismatches($sql) as $mismatch) {
                        $key = md5($mismatch['column'] . '|' . $mismatch['kind']);
                        if (isset($seenIssues[$key])) {
                            continue;
                        }

                        $seenIssues[$key] = true;

                        yield $this->createIssue($mismatch, $sql, $query);
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Implicit Type Conversion Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects predicates comparing a column to a literal of an incompatible type, which disables index usage via implicit conversion';
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
     * @return list<array{column: string, literal: string, kind: string}>
     */
    private function detectMismatches(string $sql): array
    {
        if (1 !== preg_match('/\bWHERE\b(.*?)(?:\bGROUP\s+BY\b|\bORDER\s+BY\b|\bLIMIT\b|\bHAVING\b|$)/is', $sql, $whereMatches)) {
            return [];
        }

        $whereClause = $whereMatches[1];
        $mismatches = [];

        // Numeric column compared to quoted string literal: e.g. user_id = '42'
        $numericVsStringPattern = '/([a-zA-Z_][\w]*(?:\.[a-zA-Z_][\w]*)?)\s*(?:=|<>|!=|<|>|<=|>=)\s*\'([^\']*)\'/i';
        if (preg_match_all($numericVsStringPattern, $whereClause, $allMatches, PREG_SET_ORDER) > 0) {
            foreach ($allMatches as $match) {
                $column = $match[1];
                $literal = $match[2];
                if ($this->isNumericColumnName($column) && $this->looksLikeNumericLiteral($literal)) {
                    $mismatches[] = [
                        'column' => $column,
                        'literal' => "'" . $literal . "'",
                        'kind' => 'numeric_column_vs_string_literal',
                    ];
                }
            }
        }

        // Date column compared to bare integer literal: e.g. created_at = 1700000000
        $dateVsIntPattern = '/([a-zA-Z_][\w]*(?:\.[a-zA-Z_][\w]*)?)\s*(?:=|<>|!=|<|>|<=|>=)\s*(\d+)(?!\s*[\'"])/i';
        if (preg_match_all($dateVsIntPattern, $whereClause, $allMatches, PREG_SET_ORDER) > 0) {
            foreach ($allMatches as $match) {
                $column = $match[1];
                $literal = $match[2];
                if ($this->isDateColumnName($column)) {
                    $mismatches[] = [
                        'column' => $column,
                        'literal' => $literal,
                        'kind' => 'date_column_vs_integer_literal',
                    ];
                }
            }
        }

        return $mismatches;
    }

    private function isNumericColumnName(string $column): bool
    {
        $bare = strtolower($this->stripAlias($column));
        if (in_array($bare, self::NUMERIC_COLUMN_EXACT, true)) {
            return true;
        }

        foreach (self::NUMERIC_COLUMN_SUFFIXES as $suffix) {
            if (str_ends_with($bare, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isDateColumnName(string $column): bool
    {
        $bare = strtolower($this->stripAlias($column));
        if (in_array($bare, self::DATE_COLUMN_EXACT, true)) {
            return true;
        }

        foreach (self::DATE_COLUMN_SUFFIXES as $suffix) {
            if (str_ends_with($bare, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function stripAlias(string $column): string
    {
        $dotPosition = strrpos($column, '.');
        if (false === $dotPosition) {
            return $column;
        }

        return substr($column, $dotPosition + 1);
    }

    private function looksLikeNumericLiteral(string $literal): bool
    {
        return 1 === preg_match('/^-?\d+(?:\.\d+)?$/', $literal);
    }

    /**
     * @param array{column: string, literal: string, kind: string} $mismatch
     */
    private function createIssue(array $mismatch, string $sql, array|object $query): PerformanceIssue
    {
        $description = match ($mismatch['kind']) {
            'numeric_column_vs_string_literal' => sprintf(
                'Column %s appears numeric but is compared to string literal %s. The database must convert one side ' .
                'before comparing, which usually disables index usage on %s. Bind the value with the correct PHP type, ' .
                'or remove the surrounding quotes if the literal is numeric.',
                $mismatch['column'],
                $mismatch['literal'],
                $mismatch['column'],
            ),
            'date_column_vs_integer_literal' => sprintf(
                'Column %s appears to be a date/time column but is compared to integer literal %s. The database will ' .
                'apply implicit conversion, defeating any index on %s. Pass a properly typed DateTimeInterface or a ' .
                'formatted date string (ISO 8601) instead.',
                $mismatch['column'],
                $mismatch['literal'],
                $mismatch['column'],
            ),
            default => 'Implicit type conversion detected in WHERE predicate.',
        };

        $title = match ($mismatch['kind']) {
            'numeric_column_vs_string_literal' => sprintf('Numeric Column %s Compared to String Literal', $mismatch['column']),
            'date_column_vs_integer_literal' => sprintf('Date Column %s Compared to Integer Literal', $mismatch['column']),
            default => 'Implicit Type Conversion',
        };

        $issueData = new IssueData(
            type: 'implicit_type_conversion',
            title: $title,
            description: $description,
            severity: Severity::warning(),
            suggestion: $this->createSuggestion($mismatch, $sql),
            queries: [$query], // @phpstan-ignore argument.type
            backtrace: $this->extractBacktrace($query),
        );

        return new PerformanceIssue($issueData->toArray());
    }

    /**
     * @param array{column: string, literal: string, kind: string} $mismatch
     */
    private function createSuggestion(array $mismatch, string $sql): mixed
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/implicit_type_conversion',
            context: [
                'column' => $mismatch['column'],
                'literal' => $mismatch['literal'],
                'kind' => $mismatch['kind'],
                'original_query' => $sql,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: 'Bind parameters with correct PHP types',
                tags: ['performance', 'index', 'type-conversion', 'optimization'],
            ),
        );
    }
}
