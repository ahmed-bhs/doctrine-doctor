<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

final class MissingTransactionOnBatchAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly int $threshold = 10,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(function () use ($queryDataCollection) {
            $inTransaction = false;
            $batchedWrites = [];
            $unwrappedWrites = [];

            foreach ($queryDataCollection as $queryData) {
                $sql = strtoupper(trim($queryData->sql));

                if ($this->isBegin($sql)) {
                    $inTransaction = true;
                    $batchedWrites = [];
                    continue;
                }

                if ($this->isCommitOrRollback($sql)) {
                    $inTransaction = false;
                    $batchedWrites = [];
                    continue;
                }

                if (!$queryData->isInsert() && !$queryData->isUpdate() && !$queryData->isDelete()) {
                    continue;
                }

                if ($inTransaction) {
                    continue;
                }

                $unwrappedWrites[] = $queryData;
            }

            $count = count($unwrappedWrites);
            if ($count < $this->threshold) {
                return;
            }

            $totalMs = 0.0;
            foreach ($unwrappedWrites as $q) {
                $totalMs += $q->executionTime->inMilliseconds();
            }

            $severity = match (true) {
                $count >= 50 => Severity::critical(),
                $count >= 20 => Severity::warning(),
                default => Severity::info(),
            };

            $suggestion = $this->suggestionFactory->createFromTemplate(
                templateName: 'Performance/query_optimization',
                context: [
                    'code' => $unwrappedWrites[0]->sql,
                    'optimization' => sprintf(
                        'Wrap %d INSERT/UPDATE/DELETE queries in a transaction. Each statement currently triggers its own implicit commit, causing N fsync() calls and ~10-100x slowdown.',
                        $count,
                    ),
                    'execution_time' => $totalMs,
                    'threshold' => $this->threshold,
                ],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::performance(),
                    severity: $severity,
                    title: sprintf('Batch without transaction: %d writes', $count),
                    tags: ['performance', 'transaction', 'dbal', 'batch'],
                ),
            );

            $issueData = new IssueData(
                type: IssueType::MISSING_TRANSACTION_ON_BATCH->value,
                title: sprintf('Batch writes without transaction: %d statements (%.2fms total)', $count, $totalMs),
                description: sprintf(
                    '%d write statements were executed outside any transaction. Each triggers an implicit commit and fsync, multiplying I/O cost. Wrap the batch in beginTransaction()/commit() to get a single fsync.',
                    $count,
                ),
                severity: $severity,
                suggestion: $suggestion,
                queries: $unwrappedWrites,
                backtrace: $unwrappedWrites[0]->backtrace,
            );

            yield $this->issueFactory->create($issueData);
        });
    }

    private function isBegin(string $upperSql): bool
    {
        return str_starts_with($upperSql, 'START TRANSACTION')
            || str_starts_with($upperSql, 'BEGIN')
            || str_contains($upperSql, 'BEGIN TRANSACTION')
            || str_starts_with($upperSql, 'SAVEPOINT');
    }

    private function isCommitOrRollback(string $upperSql): bool
    {
        return str_starts_with($upperSql, 'COMMIT')
            || str_starts_with($upperSql, 'ROLLBACK')
            || str_starts_with($upperSql, 'RELEASE SAVEPOINT');
    }
}
