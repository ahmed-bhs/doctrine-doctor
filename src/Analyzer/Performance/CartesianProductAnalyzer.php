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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Webmozart\Assert\Assert;

class CartesianProductAnalyzer implements AnalyzerInterface
{
    private LoggerInterface $logger;

    private const DEFAULT_N1_COLLECTION_THRESHOLD = 3;

    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly SqlStructureExtractor $sqlExtractor,
        private readonly CollectionJoinDetector $collectionJoinDetector,
        ?LoggerInterface $logger = null,
        private readonly int $n1CollectionThreshold = self::DEFAULT_N1_COLLECTION_THRESHOLD,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            function () use ($queryDataCollection) {
                Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

                $metadataMap = $this->collectionJoinDetector->buildMetadataMap();
                $seenKeys = [];

                $this->logger->debug('[CartesianProduct] metadataMap tables: ' . implode(', ', array_keys($metadataMap)));

                foreach ($queryDataCollection as $queryData) {
                    if (!$queryData->isSelect()) {
                        continue;
                    }

                    if (!$this->sqlExtractor->hasJoin($queryData->sql)) {
                        $this->logger->debug('[CartesianProduct] SKIP no join: ' . substr($queryData->sql, 0, 120));
                        continue;
                    }

                    $joins = $this->sqlExtractor->extractJoins($queryData->sql);

                    if ([] === $joins) {
                        $this->logger->debug('[CartesianProduct] SKIP extractJoins empty: ' . substr($queryData->sql, 0, 120));
                        continue;
                    }

                    $this->logger->debug('[CartesianProduct] Query has ' . count($joins) . ' joins: ' . substr($queryData->sql, 0, 200));
                    foreach ($joins as $j) {
                        $this->logger->debug('[CartesianProduct]   join: type=' . ($j['type'] ?? '?') . ' table=' . ($j['table'] ?? '?'));
                    }

                    $collectionTables = $this->findCollectionJoinTables($queryData->sql, $joins, $metadataMap);

                    $this->logger->debug('[CartesianProduct] collectionTables: [' . implode(', ', $collectionTables) . ']');

                    if (count($collectionTables) < 2) {
                        $this->logger->debug('[CartesianProduct] SKIP < 2 collection tables');
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

                yield from $this->detectCollectionN1Risk($queryDataCollection);
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

        $this->logger->debug('[CartesianProduct] findCollectionJoinTables: ' . count($leftJoins) . ' LEFT joins out of ' . count($joins) . ' total');

        if (count($leftJoins) < 2) {
            $this->logger->debug('[CartesianProduct] SKIP < 2 LEFT joins');
            return [];
        }

        $fromTable = $this->collectionJoinDetector->extractFromTable($sql, $metadataMap);

        if (null === $fromTable) {
            $this->logger->debug('[CartesianProduct] SKIP fromTable is null');
            return [];
        }

        $this->logger->debug('[CartesianProduct] fromTable: ' . $fromTable);

        $tables = [];

        foreach ($leftJoins as $join) {
            Assert::isArray($join, 'Join must be an array');

            $isCollection = $this->collectionJoinDetector->isCollectionJoin($join, $metadataMap, $sql, $fromTable);
            $this->logger->debug('[CartesianProduct] LEFT JOIN ' . ($join['table'] ?? '?') . ' isCollection=' . ($isCollection ? 'true' : 'false'));

            if ($isCollection) {
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

    /**
     * @return \Generator<IssueInterface>
     */
    private function detectCollectionN1Risk(QueryDataCollection $queryDataCollection): \Generator
    {
        /** @var array<string, array<string, list<QueryData>>> $sourceGroups */
        $sourceGroups = [];

        foreach ($queryDataCollection as $queryData) {
            if (!$queryData->isSelect()) {
                continue;
            }

            $pattern = $this->sqlExtractor->detectNPlusOnePattern($queryData->sql)
                ?? $this->sqlExtractor->detectNPlusOneFromJoin($queryData->sql);

            if (null === $pattern) {
                continue;
            }

            $sourceEntity = $pattern['foreignKey'];
            $collectionTable = $pattern['table'];

            $sourceGroups[$sourceEntity][$collectionTable][] = $queryData;
        }

        foreach ($sourceGroups as $sourceEntity => $collections) {
            $qualifiedCollections = array_filter(
                $collections,
                fn (array $queries) => count($queries) >= $this->n1CollectionThreshold,
            );

            if (count($qualifiedCollections) < 2) {
                continue;
            }

            $tables = array_keys($qualifiedCollections);
            sort($tables);

            $this->logger->debug(sprintf(
                '[CartesianProduct] N+1 risk: %d collections on "%s": %s',
                count($tables),
                $sourceEntity,
                implode(', ', $tables),
            ));

            $sampleQueries = array_map(
                fn (array $queries) => $queries[0],
                $qualifiedCollections,
            );

            yield $this->createRiskIssue($sourceEntity, $tables, array_values($sampleQueries));
        }
    }

    /**
     * @param array<string> $tables
     * @param array<QueryData> $sampleQueries
     */
    private function createRiskIssue(string $sourceEntity, array $tables, array $sampleQueries): IssueInterface
    {
        $collectionCount = count($tables);

        $issueData = new IssueData(
            type: 'cartesian_product_risk',
            title: sprintf(
                'Cartesian Product Risk: %d Collections on %s (use Multi-Step Hydration)',
                $collectionCount,
                $sourceEntity,
            ),
            description: sprintf(
                'Detected %d N+1 collection groups (%s) all loading from the same source entity "%s". ',
                $collectionCount,
                implode(', ', $tables),
                $sourceEntity,
            ) .
            'If someone attempts to fix this N+1 by adding LEFT JOINs in a single query, ' .
            sprintf('it will create a cartesian product with O(n^%d) hydration complexity. ', $collectionCount) .
            'Use multi-step hydration instead: one separate query per collection.',
            severity: Severity::warning(),
            suggestion: $this->createRiskSuggestion($collectionCount, $tables, $sourceEntity),
            queries: $sampleQueries,
            backtrace: $sampleQueries[0]->backtrace ?? null,
        );

        return $this->issueFactory->create($issueData);
    }

    /**
     * @param array<string> $tables
     */
    private function createRiskSuggestion(int $collectionCount, array $tables, string $sourceEntity): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/multi_step_hydration',
            context: [
                'join_count' => $collectionCount,
                'tables'     => $tables,
                'sql'        => sprintf('N+1 collections on %s: %s', $sourceEntity, implode(', ', $tables)),
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: sprintf(
                    'Multi-Step Hydration for %d Collections on %s (prevent O(n^%d) cartesian product)',
                    $collectionCount,
                    $sourceEntity,
                    $collectionCount,
                ),
                tags: ['performance', 'hydration', 'cartesian-product', 'n-plus-one', 'prevention'],
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
