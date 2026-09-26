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
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Detects many single-row INSERTs into the same table, the SQL the ORM writes
 * when an import persists entities one by one: one statement and one round
 * trip per row. A multi-row INSERT through DBAL writes the same rows in a few
 * statements. It bypasses the unit of work, so the issue names what the mapped
 * entity would lose: lifecycle callbacks, entity listeners, generated ids.
 */
class BulkInsertAnalyzer implements AnalyzerInterface
{
    private const string SINGLE_ROW_INSERT = '/^\s*INSERT\s+INTO\s+[`"]?(\w+)[`"]?\s*\([^)]*\)\s*VALUES\s*\([^()]*\)\s*;?\s*$/i';

    public function __construct(
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly int $threshold = 100,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                foreach ($this->groupSingleRowInsertsByTable($queryDataCollection) as $table => $queries) {
                    if (count($queries) >= $this->threshold) {
                        yield $this->createIssue($table, $queries);
                    }
                }
            },
        );
    }

    /**
     * @return array<string, list<QueryData>>
     */
    private function groupSingleRowInsertsByTable(QueryDataCollection $queryDataCollection): array
    {
        $groups = [];

        foreach ($queryDataCollection->onlyInserts() as $queryData) {
            if (1 === preg_match(self::SINGLE_ROW_INSERT, $queryData->sql, $matches)) {
                $groups[$matches[1]][] = $queryData;
            }
        }

        return $groups;
    }

    /**
     * @param list<QueryData> $queries
     */
    private function createIssue(string $table, array $queries): PerformanceIssue
    {
        $count    = count($queries);
        $metadata = $this->findMetadata($table);
        $bypassed = null === $metadata ? [] : $this->bypassedBehaviours($metadata);

        $description = sprintf(
            '%d single-row INSERT statements into %s, one round trip per row. A multi-row INSERT through DBAL ' .
            '(Connection::executeStatement) writes them in a few statements.',
            $count,
            $table,
        );

        if (null !== $metadata) {
            $description .= sprintf(
                ' It bypasses the unit of work for %s%s.',
                $metadata->getName(),
                [] === $bypassed ? '' : ', so it skips: ' . implode('; ', $bypassed),
            );
        }

        $issueData = new IssueData(
            type: 'bulk_insert',
            title: sprintf('%d Single-Row INSERTs into %s', $count, $table),
            description: $description,
            severity: Severity::warning(),
            suggestion: $this->suggestionFactory->createFromTemplate(
                templateName: 'Performance/bulk_insert',
                context: [
                    'table'       => $table,
                    'count'       => $count,
                    'sql'         => $queries[0]->sql,
                    'entity'      => $metadata?->getName(),
                    'bypassed'    => $bypassed,
                ],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::performance(),
                    severity: Severity::warning(),
                    title: 'Insert rows in batches with DBAL',
                    tags: ['performance', 'insert', 'batch', 'dbal'],
                ),
            ),
            queries: $queries,
            backtrace: $queries[0]->backtrace,
        );

        return new PerformanceIssue($issueData->toArray());
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @return list<string>
     */
    private function bypassedBehaviours(ClassMetadata $metadata): array
    {
        $bypassed  = [];
        $callbacks = array_keys(array_filter($metadata->lifecycleCallbacks));

        if ([] !== $callbacks) {
            $bypassed[] = 'lifecycle callbacks (' . implode(', ', $callbacks) . ')';
        }

        if ([] !== array_filter($metadata->entityListeners)) {
            $bypassed[] = 'entity listeners (' . implode(', ', array_keys(array_filter($metadata->entityListeners))) . ')';
        }

        if ($metadata->isIdGeneratorIdentity() || $metadata->isIdGeneratorSequence()) {
            $bypassed[] = 'generated identifiers, which are not set back on objects';
        }

        return $bypassed;
    }

    /**
     * @return ClassMetadata<object>|null
     */
    private function findMetadata(string $table): ?ClassMetadata
    {
        if (null === $this->entityManager) {
            return null;
        }

        try {
            foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
                if (0 === strcasecmp($metadata->getTableName(), $table) && !$metadata->isMappedSuperclass) {
                    return $metadata;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
