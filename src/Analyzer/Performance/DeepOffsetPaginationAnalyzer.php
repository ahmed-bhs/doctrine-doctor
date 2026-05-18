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

class DeepOffsetPaginationAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    public function __construct(
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly SqlStructureExtractor $sqlExtractor = new SqlStructureExtractor(),
        private readonly int $offsetWarningThreshold = 1000,
        private readonly int $offsetCriticalThreshold = 10000,
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

                    $offset = $this->extractOffset($sql);
                    if (null === $offset || $offset < $this->offsetWarningThreshold) {
                        continue;
                    }

                    $key = md5($this->sqlExtractor->normalizeQuery($sql));
                    if (isset($seenIssues[$key])) {
                        continue;
                    }

                    $seenIssues[$key] = true;

                    yield $this->createIssue($sql, $offset, $query);
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Deep Offset Pagination Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects deep OFFSET pagination that forces the database to scan and discard many rows';
    }

    private function extractSQL(array|object $query): string
    {
        if (is_array($query)) {
            return $query['sql'] ?? '';
        }

        return is_object($query) && property_exists($query, 'sql') ? ($query->sql ?? '') : '';
    }

    private function extractOffset(string $sql): ?int
    {
        if (1 === preg_match('/\bOFFSET\s+(\d+)\b/i', $sql, $matches)) {
            return (int) $matches[1];
        }

        if (1 === preg_match('/\bLIMIT\s+(\d+)\s*,\s*(\d+)\b/i', $sql, $matches)) {
            return (int) $matches[1];
        }

        return null;
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

    private function createIssue(string $sql, int $offset, array|object $query): PerformanceIssue
    {
        $executionTime = $this->extractExecutionTime($query);
        $severity = $offset >= $this->offsetCriticalThreshold ? Severity::critical() : Severity::warning();

        $description = sprintf(
            'Query uses OFFSET %d, which forces the database to read and discard %d rows before returning results. ' .
            'Cost grows linearly with page depth. Execution time: %.2fms. ' .
            'Replace with keyset (seek) pagination using WHERE on an indexed sortable column (e.g. WHERE id > :lastId ORDER BY id LIMIT N).',
            $offset,
            $offset,
            $executionTime,
        );

        $issueData = new IssueData(
            type: 'deep_offset_pagination',
            title: sprintf('Deep OFFSET Pagination Detected (OFFSET %d)', $offset),
            description: $description,
            severity: $severity,
            suggestion: $this->createSuggestion($sql, $offset),
            queries: [$query], // @phpstan-ignore argument.type
            backtrace: $this->extractBacktrace($query),
        );

        return new PerformanceIssue($issueData->toArray());
    }

    private function createSuggestion(string $sql, int $offset): mixed
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/deep_offset_pagination',
            context: [
                'offset' => $offset,
                'original_query' => $sql,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: 'Replace deep OFFSET with keyset pagination',
                tags: ['performance', 'pagination', 'offset', 'keyset'],
            ),
        );
    }
}
