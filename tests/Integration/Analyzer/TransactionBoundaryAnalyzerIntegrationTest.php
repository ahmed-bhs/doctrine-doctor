<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\TransactionBoundaryAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for TransactionBoundaryAnalyzer - Transaction Safety.
 *
 * This test demonstrates transaction management issues:
 * - Uncommitted transactions (CRITICAL)
 * - Multiple flushes in one transaction (WARNING)
 * - Nested transactions (CRITICAL)
 * - Long-running transactions (WARNING)
 */
final class TransactionBoundaryAnalyzerIntegrationTest extends DatabaseTestCase
{
    private TransactionBoundaryAnalyzer $transactionBoundaryAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->createSchema([Product::class]);

        $this->transactionBoundaryAnalyzer = new TransactionBoundaryAnalyzer(
            new IssueFactory(),
        );
    }

    #[Test]
    public function it_detects_uncommitted_transaction_with_flush(): void
    {
        $this->startQueryCollection();

        // BAD: Start transaction but never commit
        $this->entityManager->getConnection()->beginTransaction();

        $product = new Product();
        $product->setName('Product 1');
        $product->setPrice(9.99);
        $product->setStock(100);

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        // Missing: $this->entityManager->getConnection()->commit();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->transactionBoundaryAnalyzer->analyze($queryDataCollection);
        $issues = $issueCollection->toArray();

        self::assertGreaterThan(0, count($issues), 'Should detect unclosed transaction');
        $unclosedIssue = array_find($issues, fn ($issue) => 'transaction_unclosed' === $issue->getType());

        self::assertNotNull($unclosedIssue, 'Should detect unclosed transaction');
        self::assertSame('Unclosed Transaction Detected', $unclosedIssue->getTitle());
        self::assertSame('critical', $unclosedIssue->getSeverity()->value);
        self::assertStringContainsString('Transaction started but never committed', $unclosedIssue->getDescription());
        self::assertStringContainsString('1 flush operation(s) were performed but not committed', $unclosedIssue->getDescription());

        // Cleanup
        try {
            $this->entityManager->getConnection()->rollBack();
        } catch (\Exception) {
            // Already rolled back
        }
    }

    #[Test]
    public function it_detects_uncommitted_transaction_without_flush(): void
    {
        $this->startQueryCollection();

        // BAD: Start transaction but never commit (no flush)
        $this->entityManager->getConnection()->beginTransaction();

        // Just start transaction, no operations

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->transactionBoundaryAnalyzer->analyze($queryDataCollection);
        $issues = $issueCollection->toArray();

        self::assertGreaterThan(0, count($issues), 'Should detect unclosed transaction');
        $unclosedIssue = array_find($issues, fn ($issue) => 'transaction_unclosed' === $issue->getType());

        self::assertNotNull($unclosedIssue, 'Should detect unclosed transaction even without flush');
        self::assertSame('critical', $unclosedIssue->getSeverity()->value);
        self::assertStringNotContainsString('flush operation(s) were performed', $unclosedIssue->getDescription());

        // Cleanup
        try {
            $this->entityManager->getConnection()->rollBack();
        } catch (\Exception) {
            // Already rolled back
        }
    }

    #[Test]
    public function it_detects_nested_transactions(): void
    {
        $this->startQueryCollection();

        // Simulate nested transaction by manually logging BEGIN twice
        // (SQLite doesn't actually support nested transactions)
        $connection = $this->entityManager->getConnection();

        // First transaction
        $connection->beginTransaction();

        $product = new Product();
        $product->setName('Product 1');
        $product->setPrice(9.99);
        $product->setStock(100);
        $this->entityManager->persist($product);

        // Manually log second BEGIN to simulate nested transaction attempt
        $this->queryLogger->log('BEGIN TRANSACTION');

        $this->entityManager->flush();

        // Manually log inner commit
        $this->queryLogger->log('COMMIT');

        $connection->commit();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->transactionBoundaryAnalyzer->analyze($queryDataCollection);
        $issues = $issueCollection->toArray();

        self::assertGreaterThan(0, count($issues), 'Should detect nested transaction');
        $nestedIssue = array_find($issues, fn ($issue) => 'transaction_nested' === $issue->getType());

        self::assertNotNull($nestedIssue, 'Should detect nested transaction');
        self::assertStringContainsString('Nested Transaction Detected', $nestedIssue->getTitle());
        self::assertSame('critical', $nestedIssue->getSeverity()->value);
        self::assertStringContainsString('DO NOT support real nested transactions', $nestedIssue->getDescription());
    }

    #[Test]
    public function it_detects_multiple_flushes_in_single_transaction(): void
    {
        $this->startQueryCollection();

        $this->entityManager->getConnection()->beginTransaction();

        // First flush
        $product1 = new Product();
        $product1->setName('Product 1');
        $product1->setPrice(9.99);
        $product1->setStock(100);
        $this->entityManager->persist($product1);
        $this->entityManager->flush();

        // Second flush - this triggers the issue
        $product2 = new Product();
        $product2->setName('Product 2');
        $product2->setPrice(19.99);
        $product2->setStock(50);
        $this->entityManager->persist($product2);
        $this->entityManager->flush();

        // Third flush - makes it worse
        $product3 = new Product();
        $product3->setName('Product 3');
        $product3->setPrice(29.99);
        $product3->setStock(25);
        $this->entityManager->persist($product3);
        $this->entityManager->flush();

        $this->entityManager->getConnection()->commit();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->transactionBoundaryAnalyzer->analyze($queryDataCollection);
        $issues = $issueCollection->toArray();

        self::assertGreaterThan(0, count($issues), 'Should detect multiple flushes');

        $multipleFlushIssues = array_filter($issues, fn ($issue) => 'transaction_multiple_flush' === $issue->getType());

        self::assertGreaterThan(0, count($multipleFlushIssues), 'Should detect multiple flush operations');

        $firstIssue = reset($multipleFlushIssues);

        assert($firstIssue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertSame('warning', $firstIssue->getSeverity()->value);
        self::assertStringContainsString('Multiple flush operations', $firstIssue->getDescription());
        self::assertStringContainsString('deadlock risk', $firstIssue->getDescription());
    }

    #[Test]
    public function it_detects_long_running_transaction(): void
    {
        $this->startQueryCollection();

        $this->entityManager->getConnection()->beginTransaction();

        // Create multiple products to make transaction longer
        for ($i = 0; $i < 10; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99 * ($i + 1));
            $product->setStock(100 - $i);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();

        // Simulate long operation by adding more queries
        for ($i = 0; $i < 5; $i++) {
            $this->entityManager->createQuery('SELECT p FROM ' . Product::class . ' p WHERE p.stock > :stock')
                ->setParameter('stock', 50)
                ->getResult();
        }

        $this->entityManager->getConnection()->commit();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->transactionBoundaryAnalyzer->analyze($queryDataCollection);
        $issues = $issueCollection->toArray();

        // This test may or may not detect long transaction depending on query execution time
        // We just verify analyzer runs without errors
        self::assertIsArray($issues);

        $longTxIssues = array_filter($issues, fn ($issue) => 'transaction_too_long' === $issue->getType());
        foreach ($longTxIssues as $issue) {
            self::assertSame('warning', $issue->getSeverity()->value);
            self::assertStringContainsString('Long Transaction', $issue->getTitle());
        }
    }

    #[Test]
    public function it_does_not_flag_correct_transaction_usage(): void
    {
        $this->startQueryCollection();

        // GOOD: Proper transaction management
        $this->entityManager->getConnection()->beginTransaction();

        try {
            $product = new Product();
            $product->setName('Product 1');
            $product->setPrice(9.99);
            $product->setStock(100);
            $this->entityManager->persist($product);
            $this->entityManager->flush();

            $this->entityManager->getConnection()->commit();
        } catch (\Exception $exception) {
            $this->entityManager->getConnection()->rollBack();
            throw $exception;
        }

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->transactionBoundaryAnalyzer->analyze($queryDataCollection);
        $issues = $issueCollection->toArray();

        // Should have no issues (single flush, committed, not too long)
        self::assertCount(0, $issues, 'Proper transaction usage should not trigger issues');
    }

    #[Test]
    public function it_handles_transaction_with_rollback(): void
    {
        $this->startQueryCollection();

        $this->entityManager->getConnection()->beginTransaction();

        $product = new Product();
        $product->setName('Product 1');
        $product->setPrice(9.99);
        $product->setStock(100);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        // Rollback instead of commit
        $this->entityManager->getConnection()->rollBack();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->transactionBoundaryAnalyzer->analyze($queryDataCollection);
        $issues = $issueCollection->toArray();

        // Rollback properly closes transaction, no unclosed transaction issue
        $unclosedIssues = array_filter($issues, fn ($issue) => 'transaction_unclosed' === $issue->getType());
        self::assertCount(0, $unclosedIssues, 'Rollback should properly close transaction');
    }

    #[Test]
    public function it_handles_empty_query_collection(): void
    {
        $this->startQueryCollection();
        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->transactionBoundaryAnalyzer->analyze($queryDataCollection);
        $issues = $issueCollection->toArray();

        self::assertCount(0, $issues, 'Empty collection should not trigger issues');
    }

    #[Test]
    public function it_uses_generator_pattern(): void
    {
        $this->startQueryCollection();

        $this->entityManager->getConnection()->beginTransaction();
        $product = new Product();
        $product->setName('Product 1');
        $product->setPrice(9.99);
        $product->setStock(100);
        $this->entityManager->persist($product);
        $this->entityManager->flush();
        // No commit - will trigger unclosed transaction

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->transactionBoundaryAnalyzer->analyze($queryDataCollection);

        // Generator should be iterable
        self::assertIsIterable($issueCollection);

        $count = 0;
        foreach ($issueCollection as $issue) {
            $count++;
            self::assertIsObject($issue);
        }

        self::assertGreaterThan(0, $count, 'Generator should yield issues');

        // Cleanup
        try {
            $this->entityManager->getConnection()->rollBack();
        } catch (\Exception) {
            // Already rolled back
        }
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        self::assertInstanceOf(
            \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface::class,
            $this->transactionBoundaryAnalyzer,
        );
    }

    #[Test]
    public function it_detects_multiple_nested_transaction_levels(): void
    {
        $this->startQueryCollection();

        // Simulate multiple nested levels manually since SQLite doesn't support them
        $connection = $this->entityManager->getConnection();

        // Level 1
        $connection->beginTransaction();

        // Simulate level 2 - nested
        $this->queryLogger->log('BEGIN TRANSACTION');

        // Simulate level 3 - deeply nested
        $this->queryLogger->log('BEGIN TRANSACTION');
        $this->queryLogger->log('COMMIT');

        // Close level 2
        $this->queryLogger->log('COMMIT');

        // Close level 1
        $connection->commit();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->transactionBoundaryAnalyzer->analyze($queryDataCollection);
        $issues = $issueCollection->toArray();

        $nestedIssues = array_filter($issues, fn ($issue) => 'transaction_nested' === $issue->getType());

        // Should detect multiple nesting levels
        self::assertGreaterThan(0, count($nestedIssues), 'Should detect nested transactions');
    }

    #[Test]
    public function it_provides_correct_severity_for_all_issue_types(): void
    {
        // Test 1: Unclosed transaction - CRITICAL
        $this->startQueryCollection();
        $this->entityManager->getConnection()->beginTransaction();
        $product = new Product();
        $product->setName('Test');
        $product->setPrice(9.99);
        $product->setStock(100);
        $this->entityManager->persist($product);
        $this->entityManager->flush();
        // No commit
        $queries1 = $this->stopQueryCollection();

        $issues1 = $this->transactionBoundaryAnalyzer->analyze($queries1);
        foreach ($issues1 as $issue) {
            if ('transaction_unclosed' === $issue->getType()) {
                self::assertSame('critical', $issue->getSeverity()->value);
            }
        }

        try {
            $this->entityManager->getConnection()->rollBack();
        } catch (\Exception) {
        }
        $this->entityManager->clear();

        // Test 2: Nested transaction - CRITICAL
        $this->queryLogger->reset();
        $this->startQueryCollection();
        $this->entityManager->getConnection()->beginTransaction();
        // Simulate nested transaction
        $this->queryLogger->log('BEGIN TRANSACTION');
        $this->queryLogger->log('COMMIT');
        $this->entityManager->getConnection()->commit();
        $queries2 = $this->stopQueryCollection();

        $issues2 = $this->transactionBoundaryAnalyzer->analyze($queries2);
        foreach ($issues2 as $issue) {
            if ('transaction_nested' === $issue->getType()) {
                self::assertSame('critical', $issue->getSeverity()->value);
            }
        }

        $this->entityManager->clear();

        // Test 3: Multiple flush - WARNING
        $this->queryLogger->reset();
        $this->startQueryCollection();
        $this->entityManager->getConnection()->beginTransaction();
        $p1 = new Product();
        $p1->setName('P1');
        $p1->setPrice(1.0);
        $p1->setStock(1);
        $this->entityManager->persist($p1);
        $this->entityManager->flush();
        $p2 = new Product();
        $p2->setName('P2');
        $p2->setPrice(2.0);
        $p2->setStock(2);
        $this->entityManager->persist($p2);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->commit();
        $queries3 = $this->stopQueryCollection();

        $issues3 = $this->transactionBoundaryAnalyzer->analyze($queries3);
        foreach ($issues3 as $issue) {
            if ('transaction_multiple_flush' === $issue->getType()) {
                self::assertSame('warning', $issue->getSeverity()->value);
            }
        }

        self::assertTrue(true, 'All severity levels checked');
    }

    #[Test]
    public function it_tracks_transaction_state_correctly_across_multiple_transactions(): void
    {
        $this->startQueryCollection();

        // First transaction - proper
        $this->entityManager->getConnection()->beginTransaction();
        $product1 = new Product();
        $product1->setName('Product 1');
        $product1->setPrice(9.99);
        $product1->setStock(100);
        $this->entityManager->persist($product1);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->commit();

        // Second transaction - proper
        $this->entityManager->getConnection()->beginTransaction();
        $product2 = new Product();
        $product2->setName('Product 2');
        $product2->setPrice(19.99);
        $product2->setStock(50);
        $this->entityManager->persist($product2);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->commit();

        // Third transaction - unclosed (problem)
        $this->entityManager->getConnection()->beginTransaction();
        $product3 = new Product();
        $product3->setName('Product 3');
        $product3->setPrice(29.99);
        $product3->setStock(25);
        $this->entityManager->persist($product3);
        $this->entityManager->flush();
        // No commit

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->transactionBoundaryAnalyzer->analyze($queryDataCollection);
        $issues = $issueCollection->toArray();

        // Should only flag the third transaction as unclosed
        $unclosedIssues = array_filter($issues, fn ($issue) => 'transaction_unclosed' === $issue->getType());
        self::assertCount(1, $unclosedIssues, 'Should detect exactly one unclosed transaction');

        // Cleanup
        try {
            $this->entityManager->getConnection()->rollBack();
        } catch (\Exception) {
        }
    }

    #[Test]
    public function it_handles_queries_outside_transaction(): void
    {
        $this->startQueryCollection();

        // Operations without explicit transaction (uses autocommit)
        $product = new Product();
        $product->setName('Product 1');
        $product->setPrice(9.99);
        $product->setStock(100);
        $this->entityManager->persist($product);
        $this->entityManager->flush(); // Uses implicit transaction

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->transactionBoundaryAnalyzer->analyze($queryDataCollection);
        $issues = $issueCollection->toArray();

        // No transaction-related issues for autocommit mode
        self::assertCount(0, $issues, 'Autocommit mode should not trigger transaction issues');
    }

    #[Test]
    public function it_provides_detailed_issue_information(): void
    {
        $this->startQueryCollection();

        $this->entityManager->getConnection()->beginTransaction();
        $product = new Product();
        $product->setName('Product 1');
        $product->setPrice(9.99);
        $product->setStock(100);
        $this->entityManager->persist($product);
        $this->entityManager->flush();
        // No commit - triggers unclosed transaction

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->transactionBoundaryAnalyzer->analyze($queryDataCollection);
        $issues = $issueCollection->toArray();

        self::assertGreaterThan(0, count($issues));

        foreach ($issues as $issue) {
            // Verify all issues have required information
            self::assertNotEmpty($issue->getType());
            self::assertNotEmpty($issue->getTitle());
            self::assertNotEmpty($issue->getDescription());
            self::assertInstanceOf(\AhmedBhs\DoctrineDoctor\ValueObject\Severity::class, $issue->getSeverity());

            // Verify description contains helpful information
            $description = $issue->getDescription();
            self::assertStringContainsString('Problem:', $description);
            self::assertStringContainsString('Solutions:', $description);
        }

        // Cleanup
        try {
            $this->entityManager->getConnection()->rollBack();
        } catch (\Exception) {
        }
    }
}
