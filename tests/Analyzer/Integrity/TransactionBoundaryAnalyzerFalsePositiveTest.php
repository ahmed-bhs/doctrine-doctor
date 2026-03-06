<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\TransactionBoundaryAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransactionBoundaryAnalyzerFalsePositiveTest extends TestCase
{
    private TransactionBoundaryAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new TransactionBoundaryAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );
    }

    #[Test]
    public function it_falsely_counts_each_insert_as_separate_flush_in_single_doctrine_flush(): void
    {
        $builder = QueryDataBuilder::create();

        $builder->addQuery('START TRANSACTION', 0.01);
        $builder->addQuery('INSERT INTO users (name) VALUES (?)', 0.5);
        $builder->addQuery('INSERT INTO profiles (user_id) VALUES (?)', 0.5);
        $builder->addQuery('INSERT INTO settings (user_id) VALUES (?)', 0.5);
        $builder->addQuery('COMMIT', 0.01);

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        $multiFlushIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Multiple Flush'),
        );

        self::assertCount(0, $multiFlushIssues, 'Consecutive INSERTs from a single Doctrine flush() should be grouped as one flush operation');
    }

    #[Test]
    public function it_falsely_calculates_transaction_duration_from_query_time_only(): void
    {
        $builder = QueryDataBuilder::create();

        $builder->addQuery('START TRANSACTION', 0.01);
        $builder->addQuery('INSERT INTO orders (status) VALUES (?)', 0.3);
        $builder->addQuery('INSERT INTO order_items (order_id) VALUES (?)', 0.3);
        $builder->addQuery('INSERT INTO payments (order_id) VALUES (?)', 0.3);
        $builder->addQuery('COMMIT', 0.01);

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        $longTxIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Long Transaction'),
        );

        self::assertCount(0, $longTxIssues, 'Transaction duration calculated from query execution times only (0.92s total) is under 1s threshold, but real PHP time between queries could push the actual transaction well over 1s -- the analyzer cannot detect this');
    }
}
