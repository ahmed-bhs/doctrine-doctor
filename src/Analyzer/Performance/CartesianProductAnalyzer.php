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
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\CollectionJoinDetector;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Webmozart\Assert\Assert;

class CartesianProductAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly SqlStructureExtractor $sqlExtractor,
        private readonly CollectionJoinDetector $collectionJoinDetector,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            function () use ($queryDataCollection) {
                Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

                $metadataMap = $this->collectionJoinDetector->buildMetadataMap();
                $seenKeys = [];

                foreach ($queryDataCollection as $queryData) {
                    if (!$queryData->isSelect()) {
                        continue;
                    }

                    if (!$this->sqlExtractor->hasJoin($queryData->sql)) {
                        continue;
                    }

                    $joins = $this->sqlExtractor->extractJoins($queryData->sql);

                    if ([] === $joins) {
                        continue;
                    }

                    $collectionTables = $this->findCollectionJoinTables($queryData->sql, $joins, $metadataMap);

                    if (count($collectionTables) < 2) {
                        continue;
                    }

                    $sorted = $collectionTables;
                    sort($sorted);
                    $key = implode(',', $sorted);

                    if (isset($seenKeys[$key])) {
                        continue;
                    }

                    $seenKeys[$key] = true;
                    yield $this->createIssue($queryData, $collectionTables);
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Cartesian Product Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects cartesian product from multiple collection JOINs causing O(n^m) hydration complexity';
    }

    /**
     * @param array<mixed> $joins
     * @param array<string, \Doctrine\ORM\Mapping\ClassMetadata> $metadataMap
     * @return array<string>
     */
    private function findCollectionJoinTables(string $sql, array $joins, array $metadataMap): array
    {
        $leftJoins = array_filter($joins, fn (array $join) => 'LEFT' === $join['type']);

        if (count($leftJoins) < 2) {
            return [];
        }

        $fromTable = $this->collectionJoinDetector->extractFromTable($sql, $metadataMap);

        if (null === $fromTable) {
            return [];
        }

        $tables = [];

        foreach ($leftJoins as $join) {
            Assert::isArray($join, 'Join must be an array');

            if ($this->collectionJoinDetector->isCollectionJoin($join, $metadataMap, $sql, $fromTable)) {
                $tables[] = $join['table'];
            }
        }

        return $tables;
    }

    /**
     * @param array<string> $tables
     */
    private function createIssue(QueryData $queryData, array $tables): IssueInterface
    {
        $joinCount = count($tables);
        $sql = $this->truncateQuery($queryData->sql);

        $issueData = new IssueData(
            type: 'cartesian_product',
            title: sprintf('Cartesian Product: %d Collection JOINs Causing O(n^%d) Hydration', $joinCount, $joinCount),
            description: sprintf(
                'Query performs %d LEFT JOINs on collection-valued associations (%s). ',
                $joinCount,
                implode(', ', $tables),
            ) .
            'This creates a cartesian product that exponentially increases SQL rows and hydration cost. ' .
            sprintf(
                'With %d collections, each row is duplicated across all combinations (O(n^%d)). ',
                $joinCount,
                $joinCount,
            ) .
            'Use multi-step hydration to split into separate queries.',
            severity: Severity::critical(),
            suggestion: $this->createSuggestion($joinCount, $tables, $sql),
            queries: [$queryData],
            backtrace: $queryData->backtrace,
        );

        return $this->issueFactory->create($issueData);
    }

    /**
     * @param array<string> $tables
     */
    private function createSuggestion(int $joinCount, array $tables, string $sql): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/multi_step_hydration',
            context: [
                'join_count' => $joinCount,
                'tables'     => $tables,
                'sql'        => $sql,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::critical(),
                title: sprintf('Multi-Step Hydration for %d Collection JOINs (O(n^%d) complexity)', $joinCount, $joinCount),
                tags: ['performance', 'hydration', 'cartesian-product', 'join', 'optimization'],
            ),
        );
    }

    private function truncateQuery(string $sql, int $maxLength = 200): string
    {
        if (strlen($sql) <= $maxLength) {
            return $sql;
        }

        return substr($sql, 0, $maxLength) . '...';
    }
}
