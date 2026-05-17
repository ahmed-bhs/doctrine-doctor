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

class PaginationWithoutOrderByAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
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

                    $hasLimit = $this->sqlExtractor->hasLimit($sql);
                    $hasOffset = $this->sqlExtractor->hasOffset($sql);

                    if (!$hasLimit && !$hasOffset) {
                        continue;
                    }

                    if ($this->sqlExtractor->hasOrderBy($sql)) {
                        continue;
                    }

                    if ($this->returnsSingleRow($sql)) {
                        continue;
                    }

                    $key = md5($this->sqlExtractor->normalizeQuery($sql));
                    if (isset($seenIssues[$key])) {
                        continue;
                    }

                    $seenIssues[$key] = true;

                    yield $this->createIssue($sql, $hasOffset, $query);
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Pagination Without ORDER BY Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects LIMIT/OFFSET pagination without ORDER BY, leading to unstable, non-deterministic page results';
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
     * LIMIT 1 (or LIMIT 0, 1) is a single-row fetch -- ordering does not matter for correctness.
     */
    private function returnsSingleRow(string $sql): bool
    {
        if (1 === preg_match('/\bLIMIT\s+1\b(?!\s*,)/i', $sql)) {
            return true;
        }

        return 1 === preg_match('/\bLIMIT\s+\d+\s*,\s*1\b/i', $sql);
    }

    private function createIssue(string $sql, bool $hasOffset, array|object $query): PerformanceIssue
    {
        $severity = $hasOffset ? Severity::warning() : Severity::info();

        $description = sprintf(
            'Query uses %s without ORDER BY. SQL standard does not guarantee result order when no ORDER BY is provided, ' .
            'so the same page can return different rows on different executions, and consecutive pages can return ' .
            'duplicates or skip rows. Add a deterministic ORDER BY on a stable, indexed column (typically the primary key).',
            $hasOffset ? 'LIMIT/OFFSET pagination' : 'LIMIT',
        );

        $issueData = new IssueData(
            type: 'pagination_without_order_by',
            title: 'Pagination Without ORDER BY (Non-Deterministic Results)',
            description: $description,
            severity: $severity,
            suggestion: $this->createSuggestion($sql),
            queries: [$query], // @phpstan-ignore argument.type
            backtrace: $this->extractBacktrace($query),
        );

        return new PerformanceIssue($issueData->toArray());
    }

    private function createSuggestion(string $sql): mixed
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/pagination_without_order_by',
            context: [
                'original_query' => $sql,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: 'Add a deterministic ORDER BY to paginated queries',
                tags: ['performance', 'pagination', 'order-by', 'correctness'],
            ),
        );
    }
}
