<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Webmozart\Assert\Assert;

/**
 * Detects transaction boundary issues and improper transaction management.
 * Critical issues detected:
 * - Transactions never committed (hanging transactions)
 * - Multiple flush() calls within a single transaction (deadlock risk)
 * - Nested transactions (not supported by most databases)
 * - flush() outside transactions for critical operations
 * - Transactions held open too long (> 1 second)
 * - Rollback missing in exception handlers
 * Example problems:
 *   beginTransaction();
 *   flush();
 *   flush(); // Multiple flushes = deadlock risk
 *   // Missing commit = hanging transaction!
 * Impact: Data loss, deadlocks, hanging connections, database locks
 */
class TransactionBoundaryAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /** @var int Threshold for flush count in a single transaction */
    private const int MAX_FLUSH_PER_TRANSACTION = 1;

    /** @var float Maximum transaction duration in seconds */
    private const float MAX_TRANSACTION_DURATION = 1.0;

    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                $state = $this->initializeTransactionState();

                Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $queryData) {
                    $this->updateTimeState($queryData, $state);

                    $sql = strtoupper(trim($queryData->sql));
                    yield from $this->processQuery($sql, $queryData, $state);
                }

                yield from $this->checkUnclosedTransactions($state);
            },
        );
    }

    /**
     * Update time tracking state.
     * @param array<string, mixed> $state
     */
    private function updateTimeState(QueryData $queryData, array &$state): void
    {
        $state['currentTime'] = $state['lastQueryTime'] + $queryData->executionTime->inMilliseconds() / 1000;
        $state['lastQueryTime'] = $state['currentTime'];
    }

    /**
     * Initialize transaction tracking state.
     * @return array<string, mixed>
     */
    private function initializeTransactionState(): array
    {
        return [
            'transactionStack'            => [],
            'flushesInCurrentTransaction' => [],
            'transactionStartTime'        => null,
            'lastQueryTime'               => 0,
            'currentTime'                 => 0,
        ];
    }

    /**
     * Process a single query and yield issues.
     * @param array<string, mixed> $state
     * @return \Generator<IssueInterface>
     */
    private function processQuery(string $sql, QueryData $queryData, array &$state): \Generator
    {
        if ($this->isBeginTransaction($sql)) {
            yield from $this->handleTransactionStart($queryData, $state);
            return;
        }

        if (!$this->hasActiveTransaction($state)) {
            return;
        }

        if ($this->isFlushQuery($sql)) {
            yield from $this->handleFlush($queryData, $state);
        } elseif ($this->isCommit($sql)) {
            yield from $this->handleCommit($queryData, $state);
        } elseif ($this->isRollback($sql)) {
            $this->handleRollback($state);
        }
    }

    /**
     * Check if there's an active transaction.
     * @param array<string, mixed> $state
     */
    private function hasActiveTransaction(array $state): bool
    {
        return [] !== $state['transactionStack'];
    }

    /**
     * Handle transaction start.
     * @param array<string, mixed> $state
     * @return \Generator<IssueInterface>
     */
    private function handleTransactionStart(QueryData $queryData, array &$state): \Generator
    {
        if ([] !== $state['transactionStack']) {
            yield $this->createNestedTransactionIssue($queryData, count($state['transactionStack']));
        }

        $transactionId                                         = uniqid('tx_', true);
        $state['transactionStack'][]                           = $transactionId;
        $state['flushesInCurrentTransaction'][$transactionId]  = 0;
        $state['transactionStartTime']                         = $state['currentTime'];
    }

    /**
     * Handle flush operation.
     * @param array<string, mixed> $state
     * @return \Generator<IssueInterface>
     */
    private function handleFlush(QueryData $queryData, array &$state): \Generator
    {
        $currentTxId = end($state['transactionStack']);
        ++$state['flushesInCurrentTransaction'][$currentTxId];

        if ($state['flushesInCurrentTransaction'][$currentTxId] > self::MAX_FLUSH_PER_TRANSACTION) {
            yield $this->createMultipleFlushIssue(
                $queryData,
                (int) $state['flushesInCurrentTransaction'][$currentTxId],
            );
        }
    }

    /**
     * Handle transaction commit.
     * @param array<string, mixed> $state
     * @return \Generator<IssueInterface>
     */
    private function handleCommit(QueryData $queryData, array &$state): \Generator
    {
        $transactionId = array_pop($state['transactionStack']);
        $duration      = $state['currentTime'] - $state['transactionStartTime'];

        if ($duration > self::MAX_TRANSACTION_DURATION) {
            yield $this->createLongTransactionIssue($queryData, $duration);
        }

        unset($state['flushesInCurrentTransaction'][$transactionId]);
        $state['transactionStartTime'] = [] === $state['transactionStack'] ? null : $state['transactionStartTime'];
    }

    /**
     * Handle transaction rollback.
     * @param array<string, mixed> $state
     */
    private function handleRollback(array &$state): void
    {
        $transactionId = array_pop($state['transactionStack']);
        unset($state['flushesInCurrentTransaction'][$transactionId]);
        $state['transactionStartTime'] = [] === $state['transactionStack'] ? null : $state['transactionStartTime'];
    }

    /**
     * Check for unclosed transactions.
     * @param array<string, mixed> $state
     * @return \Generator<IssueInterface>
     */
    private function checkUnclosedTransactions(array $state): \Generator
    {
        Assert::isIterable($state['transactionStack'], 'transactionStack must be iterable');

        foreach ($state['transactionStack'] as $txId) {
            yield $this->createUnclosedTransactionIssue($state['flushesInCurrentTransaction'][$txId] ?? 0);
        }
    }

    /**
     * Check if query is a BEGIN TRANSACTION.
     */
    private function isBeginTransaction(string $sql): bool
    {
        return str_starts_with($sql, 'START TRANSACTION')
               || str_starts_with($sql, 'BEGIN')
               || str_contains($sql, 'BEGIN TRANSACTION');
    }

    /**
     * Check if query is a flush operation (INSERT/UPDATE/DELETE).
     */
    private function isFlushQuery(string $sql): bool
    {
        return str_starts_with($sql, 'INSERT')
               || str_starts_with($sql, 'UPDATE')
               || str_starts_with($sql, 'DELETE');
    }

    /**
     * Check if query is a COMMIT.
     */
    private function isCommit(string $sql): bool
    {
        return str_starts_with($sql, 'COMMIT');
    }

    /**
     * Check if query is a ROLLBACK.
     */
    private function isRollback(string $sql): bool
    {
        return str_starts_with($sql, 'ROLLBACK');
    }

    /**
     * Create issue for nested transactions.
     */
    private function createNestedTransactionIssue(QueryData $queryData, int $depth): IssueInterface
    {
        $description = sprintf("Nested transaction detected (depth: %d).\n", $depth);
        $description .= "Impact: Inner transactions are usually ignored on MySQL/PostgreSQL.\n";
        $description .= "Impact: Inner commit/rollback can affect the outer transaction unexpectedly.\n";
        $description .= "Impact: This can lead to partial commits and inconsistent data.";

        $issueData = new IssueData(
            type: IssueType::TRANSACTION_NESTED->value,
            title: sprintf('Nested Transaction Detected (Depth: %d)', $depth),
            description: $description,
            severity: Severity::critical(),
            suggestion: null,
            queries: [$queryData],
            backtrace: $queryData->backtrace,
        );

        return $this->issueFactory->create($issueData);
    }

    /**
     * Create issue for multiple flush in transaction.
     */
    private function createMultipleFlushIssue(QueryData $queryData, int $flushCount): IssueInterface
    {
        $description = sprintf("Multiple flush() calls detected in one transaction (%d).\n", $flushCount);
        $description .= "Impact: Each flush adds round-trips and lock acquisitions.\n";
        $description .= "Impact: Deadlock risk increases and transaction throughput degrades.\n";
        $description .= sprintf("Impact: At least %d extra flush round-trips were executed.", max(0, $flushCount - 1));

        $issueData = new IssueData(
            type: IssueType::TRANSACTION_MULTIPLE_FLUSH->value,
            title: sprintf('Multiple Flush in Transaction (%d flushes)', $flushCount),
            description: $description,
            severity: Severity::warning(),
            suggestion: null,
            queries: [$queryData],
            backtrace: $queryData->backtrace,
        );

        return $this->issueFactory->create($issueData);
    }

    /**
     * Create issue for unclosed transaction.
     */
    private function createUnclosedTransactionIssue(int $flushCount): IssueInterface
    {
        $description = "Transaction started but never committed or rolled back.\n";
        $description .= "Impact: Locks may stay open and reduce connection availability.\n";
        $description .= "Impact: Work may be rolled back when the connection closes.\n";
        if ($flushCount > 0) {
            $description .= sprintf("Impact: %d flush operation(s) were executed in an unclosed transaction.", $flushCount);
        } else {
            $description .= "Impact: This can cause pool exhaustion and timeout chains.";
        }

        $issueData = new IssueData(
            type: IssueType::TRANSACTION_UNCLOSED->value,
            title: 'Unclosed Transaction Detected',
            description: $description,
            severity: Severity::critical(),
            suggestion: null,
            queries: [],
        );

        return $this->issueFactory->create($issueData);
    }

    /**
     * Create issue for long-running transaction.
     */
    private function createLongTransactionIssue(QueryData $queryData, float $duration): IssueInterface
    {
        $description = sprintf(
            "Transaction held open for %.2fs (threshold: %.2fs).\n",
            $duration,
            self::MAX_TRANSACTION_DURATION,
        );
        $description .= "Impact: Longer lock retention increases contention and deadlock probability.\n";
        $description .= "Impact: Concurrent queries can be blocked, causing latency spikes.\n";
        $description .= "Impact: Timeout and retry pressure may increase under load.";

        $issueData = new IssueData(
            type: IssueType::TRANSACTION_TOO_LONG->value,
            title: sprintf('Long Transaction (%.2fs)', $duration),
            description: $description,
            severity: Severity::warning(),
            suggestion: null,
            queries: [$queryData],
            backtrace: $queryData->backtrace,
        );

        return $this->issueFactory->create($issueData);
    }
}
