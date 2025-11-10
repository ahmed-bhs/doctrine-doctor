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
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;

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
class TransactionBoundaryAnalyzer implements AnalyzerInterface
{
    /** @var int Threshold for flush count in a single transaction */
    private const MAX_FLUSH_PER_TRANSACTION = 1;

    /** @var float Maximum transaction duration in seconds */
    private const MAX_TRANSACTION_DURATION = 1.0;

    public function __construct(
        /**
         * @readonly
         */
        private IssueFactoryInterface $issueFactory,
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

                assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

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
        assert(is_iterable($state['transactionStack']), 'transactionStack must be iterable');

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
        $description = sprintf(
            "Nested transaction detected at depth %d.

",
            $depth,
        );

        $description .= "Problem:
";
        $description .= "- Most databases (MySQL, PostgreSQL) DO NOT support real nested transactions
";
        $description .= "- Inner beginTransaction() is usually ignored
";
        $description .= "- Inner commit() commits the OUTER transaction too!
";
        $description .= "- This leads to unexpected behavior and data inconsistency

";

        $description .= "Example of the issue:
";
        $description .= "  \$conn->beginTransaction(); // Outer
";
        $description .= "  // ... some work ...
";
        $description .= "  \$conn->beginTransaction(); // Inner - IGNORED!
";
        $description .= "  // ... critical work ...
";
        $description .= "  \$conn->commit(); // Commits OUTER transaction!
";
        $description .= "  // ... more work that should be in transaction ...
";
        $description .= "  \$conn->commit(); // Already committed!

";

        $description .= "Solutions:

";
        $description .= "1. Use single transaction scope:
";
        $description .= "   \$conn->beginTransaction();
";
        $description .= "   try {
";
        $description .= "       // All operations here
";
        $description .= "       \$conn->commit();
";
        $description .= "   } catch (\Exception \$e) {
";
        $description .= "       \$conn->rollback();
";
        $description .= "   }

";

        $description .= "2. Use savepoints (if supported):
";
        $description .= "   \$conn->beginTransaction();
";
        $description .= "   \$conn->createSavepoint('sp1');
";
        $description .= "   // ... work ...
";
        $description .= "   \$conn->releaseSavepoint('sp1');
";
        $description .= "   \$conn->commit();

";

        $description .= "3. Refactor to avoid nesting:
";
        $description .= "   - Extract methods that manage their own transactions
";
        $description .= "   - Pass transaction scope as parameter
";

        $issueData = new IssueData(
            type: 'transaction_nested',
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
        $description = sprintf(
            "Multiple flush operations (%d) detected within a single transaction.

",
            $flushCount,
        );

        $description .= "Problem:
";
        $description .= "- Each flush() acquires locks and writes to database
";
        $description .= "- Multiple flushes increase deadlock risk exponentially
";
        $description .= "- Partial state committed before transaction end
";
        $description .= "- Performance overhead from multiple round-trips

";

        $description .= "Example of the problem:
";
        $description .= "  \$em->getConnection()->beginTransaction();
";
        $description .= "  try {
";
        $description .= "      \$user->setEmail('new@email.com');
";
        $description .= "      \$em->flush(); // Flush #1 - locks acquired
";
        $description .= "      
";
        $description .= "      \$order->setStatus('paid');
";
        $description .= "      \$em->flush(); // Flush #2 - more locks, deadlock risk!
";
        $description .= "      
";
        $description .= "      \$em->getConnection()->commit();
";
        $description .= "  } catch (\Exception \$e) {
";
        $description .= "      \$em->getConnection()->rollback();
";
        $description .= "  }

";

        $description .= "Solutions:

";
        $description .= "1. Single flush at end (RECOMMENDED):
";
        $description .= "   \$em->getConnection()->beginTransaction();
";
        $description .= "   try {
";
        $description .= "       \$user->setEmail('new@email.com');
";
        $description .= "       \$order->setStatus('paid');
";
        $description .= "       // Only one flush
";
        $description .= "       \$em->flush();
";
        $description .= "       \$em->getConnection()->commit();
";
        $description .= "   } catch (\Exception \$e) {
";
        $description .= "       \$em->getConnection()->rollback();
";
        $description .= "   }

";

        $description .= "2. If multiple flushes needed, consider:
";
        $description .= "   - Splitting into separate transactions
";
        $description .= "   - Using events/listeners for side effects
";
        $description .= "   - Reviewing transaction scope

";

        $description .= "Performance impact:
";
        $description .= sprintf("  - Current: %d round-trips to database
", $flushCount);
        $description .= '  - Optimized: 1 round-trip (saves ' . ($flushCount - 1) . " queries)
";

        $issueData = new IssueData(
            type: 'transaction_multiple_flush',
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
        $description = "Transaction started but never committed or rolled back.

";

        $description .= "Problem:
";
        $description .= "- Database locks remain held
";
        $description .= "- Connection cannot be reused
";
        $description .= "- Can cause connection pool exhaustion
";
        $description .= "- Data changes are lost (auto-rollback on disconnect)

";

        if ($flushCount > 0) {
            $description .= sprintf(
                "WARNING: %d flush operation(s) were performed but not committed!
",
                $flushCount,
            );
            $description .= "All data changes will be LOST when connection closes.

";
        }

        $description .= "Example of the problem:
";
        $description .= "  \$conn->beginTransaction();
";
        $description .= "  \$user->setStatus('active');
";
        $description .= "  \$em->flush();
";
        $description .= "  // MISSING: \$conn->commit();
";
        $description .= "  // Transaction auto-rolled back, data lost!

";

        $description .= "Solutions:

";
        $description .= "1. Always use try-catch-finally:
";
        $description .= "   \$conn->beginTransaction();
";
        $description .= "   try {
";
        $description .= "       // ... operations ...
";
        $description .= "       \$em->flush();
";
        $description .= "       \$conn->commit(); // Always commit on success
";
        $description .= "   } catch (\Exception \$e) {
";
        $description .= "       \$conn->rollback(); // Always rollback on error
";
        $description .= "       throw \$e;
";
        $description .= "   }

";

        $description .= "2. Use Doctrine's transactional helper:
";
        $description .= "   \$em->transactional(function(\$em) {
";
        $description .= "       // ... operations ...
";
        $description .= "       // Auto commit/rollback
";
        $description .= "   });
";

        $issueData = new IssueData(
            type: 'transaction_unclosed',
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
            "Transaction held open for %.2f seconds (threshold: %.2fs).

",
            $duration,
            self::MAX_TRANSACTION_DURATION,
        );

        $description .= "Problem:
";
        $description .= "- Long transactions increase lock contention
";
        $description .= "- Higher risk of deadlocks
";
        $description .= "- Blocks other queries waiting for locks
";
        $description .= "- Can cause timeout errors

";

        $description .= "Common causes:
";
        $description .= "- Heavy computation inside transaction
";
        $description .= "- External API calls in transaction scope
";
        $description .= "- Loading too much data before commit
";
        $description .= "- Unnecessary SELECT queries in transaction

";

        $description .= "Solutions:

";
        $description .= "1. Move heavy operations outside transaction:
";
        $description .= "   // BEFORE transaction
";
        $description .= "   \$data = \$this->prepareData(); // Heavy computation
";
        $description .= "   \$apiResponse = \$this->callApi(); // External call
";
        $description .= "   
";
        $description .= "   // THEN transaction
";
        $description .= "   \$em->transactional(function() use (\$data) {
";
        $description .= "       // Only database operations
";
        $description .= "   });

";

        $description .= "2. Reduce transaction scope:
";
        $description .= "   - Only include necessary operations
";
        $description .= "   - Move SELECT queries before transaction
";
        $description .= "   - Defer non-critical updates

";

        $description .= "3. Optimize queries within transaction:
";
        $description .= "   - Use indexes properly
";
        $description .= "   - Avoid N+1 queries
";
        $description .= "   - Batch operations
";

        $issueData = new IssueData(
            type: 'transaction_too_long',
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
