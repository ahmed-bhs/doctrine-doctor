<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Service;

use AhmedBhs\DoctrineDoctor\Service\MessageEnhancer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MessageEnhancer.
 */
final class MessageEnhancerTest extends TestCase
{
    private MessageEnhancer $enhancer;

    protected function setUp(): void
    {
        $this->enhancer = new MessageEnhancer();
    }

    #[Test]
    public function it_enhances_n_plus_one_message_with_entity_name(): void
    {
        $enhanced = $this->enhancer->enhanceNPlusOneMessage(
            'Base message',
            212,
            95.5,
            'BillLine',
        );

        self::assertStringContainsString('Base message', $enhanced);
        self::assertStringContainsString('Entity: BillLine', $enhanced);
        self::assertStringContainsString('Impact: 212 queries, 95.50ms total time', $enhanced);
        self::assertStringContainsString('CRITICAL', $enhanced);
    }

    #[Test]
    public function it_enhances_n_plus_one_message_without_entity_name(): void
    {
        $enhanced = $this->enhancer->enhanceNPlusOneMessage(
            'Base message',
            15,
            12.0,
            null,
        );

        self::assertStringContainsString('Base message', $enhanced);
        self::assertStringNotContainsString('Entity:', $enhanced);
        self::assertStringContainsString('Impact: 15 queries, 12.00ms total time', $enhanced);
    }

    #[Test]
    public function it_adds_severity_context_for_high_query_count(): void
    {
        $enhanced = $this->enhancer->enhanceNPlusOneMessage(
            'N+1 detected',
            150,
            50.0,
        );

        self::assertStringContainsString('CRITICAL', $enhanced);
        self::assertStringContainsString('significant performance degradation', $enhanced);
    }

    #[Test]
    public function it_enhances_missing_index_message_with_critical_impact(): void
    {
        $enhanced = $this->enhancer->enhanceMissingIndexMessage(
            'Missing index detected',
            138547,
            45.2,
            'time_entry',
        );

        self::assertStringContainsString('Table: time_entry', $enhanced);
        self::assertStringContainsString('138,547 rows scanned', $enhanced);
        self::assertStringContainsString('45.20ms', $enhanced);
        self::assertStringContainsString('CRITICAL', $enhanced);
        self::assertStringContainsString('add index immediately', $enhanced);
    }

    #[Test]
    public function it_enhances_missing_index_message_with_medium_impact(): void
    {
        $enhanced = $this->enhancer->enhanceMissingIndexMessage(
            'Missing index detected',
            5000,
            8.5,
            'user',
        );

        self::assertStringContainsString('5,000 rows scanned', $enhanced);
        self::assertStringContainsString('MEDIUM', $enhanced);
    }

    #[Test]
    public function it_enhances_slow_query_message_with_critical_timing(): void
    {
        $enhanced = $this->enhancer->enhanceSlowQueryMessage(
            'Slow query detected',
            150.5,
            100,
        );

        self::assertStringContainsString('Query time: 150.50ms', $enhanced);
        self::assertStringContainsString('Rows returned: 100', $enhanced);
        self::assertStringContainsString('CRITICAL', $enhanced);
        self::assertStringContainsString('User-noticeable delay', $enhanced);
    }

    #[Test]
    public function it_enhances_slow_query_message_without_row_count(): void
    {
        $enhanced = $this->enhancer->enhanceSlowQueryMessage(
            'Slow query detected',
            75.0,
            null,
        );

        self::assertStringContainsString('Query time: 75.00ms', $enhanced);
        self::assertStringNotContainsString('Rows returned:', $enhanced);
        self::assertStringContainsString('HIGH', $enhanced);
    }

    #[Test]
    public function it_enhances_order_by_without_limit_message_with_huge_dataset(): void
    {
        $enhanced = $this->enhancer->enhanceOrderByWithoutLimitMessage(
            'ORDER BY without LIMIT',
            15000,
            50.0,
        );

        self::assertStringContainsString('Sorting 15,000 rows', $enhanced);
        self::assertStringContainsString('50.00ms', $enhanced);
        self::assertStringContainsString('CRITICAL', $enhanced);
        self::assertStringContainsString('huge dataset', $enhanced);
        self::assertStringContainsString('Add LIMIT or add pagination', $enhanced);
    }

    #[Test]
    public function it_enhances_order_by_without_limit_message_with_moderate_dataset(): void
    {
        $enhanced = $this->enhancer->enhanceOrderByWithoutLimitMessage(
            'ORDER BY without LIMIT',
            500,
            5.0,
        );

        // 500 rows and 5ms falls into MEDIUM category (>100 rows but not slow)
        self::assertStringContainsString('MEDIUM', $enhanced);
        self::assertStringContainsString('Moderate sort operation', $enhanced);
    }

    #[Test]
    public function it_enhances_order_by_without_limit_message_with_small_dataset(): void
    {
        $enhanced = $this->enhancer->enhanceOrderByWithoutLimitMessage(
            'ORDER BY without LIMIT',
            50,
            1.5,
        );

        // 50 rows and 1.5ms falls into LOW category (<100 rows, not slow)
        self::assertStringContainsString('LOW', $enhanced);
        self::assertStringContainsString('Small result set', $enhanced);
    }

    #[Test]
    public function it_enhances_order_by_without_limit_message_with_tiny_dataset(): void
    {
        $enhanced = $this->enhancer->enhanceOrderByWithoutLimitMessage(
            'ORDER BY without LIMIT',
            30,
            0.8,
        );

        self::assertStringContainsString('LOW', $enhanced);
        self::assertStringContainsString('30 rows', $enhanced);
        self::assertStringContainsString('acceptable', $enhanced);
    }

    #[Test]
    public function it_enhances_find_all_message_with_huge_dataset(): void
    {
        $enhanced = $this->enhancer->enhanceFindAllMessage(
            'findAll() detected',
            15000,
            100.0,
            'User',
        );

        self::assertStringContainsString('Entity: User', $enhanced);
        self::assertStringContainsString('Loading 15,000 rows', $enhanced);
        self::assertStringContainsString('CRITICAL', $enhanced);
        self::assertStringContainsString('Use findBy() with criteria or add pagination', $enhanced);
    }

    #[Test]
    public function it_enhances_find_all_message_with_small_table(): void
    {
        $enhanced = $this->enhancer->enhanceFindAllMessage(
            'findAll() detected',
            50,
            2.0,
            'Role',
        );

        self::assertStringContainsString('LOW', $enhanced);
        self::assertStringContainsString('50 rows', $enhanced);
        self::assertStringContainsString('acceptable for now', $enhanced);
        self::assertStringContainsString('Monitor as table grows', $enhanced);
    }

    #[Test]
    public function it_enhances_hydration_message_with_high_memory(): void
    {
        $enhanced = $this->enhancer->enhanceHydrationMessage(
            'Large hydration detected',
            15000,
            60 * 1024 * 1024, // 60 MB
        );

        self::assertStringContainsString('Hydrating 15,000 entities', $enhanced);
        self::assertStringContainsString('Memory usage: 60.00 MB', $enhanced);
        self::assertStringContainsString('CRITICAL', $enhanced);
        self::assertStringContainsString('batch processing or DTO hydration', $enhanced);
    }

    #[Test]
    public function it_enhances_hydration_message_without_memory_info(): void
    {
        $enhanced = $this->enhancer->enhanceHydrationMessage(
            'Large hydration detected',
            8000,
            null,
        );

        self::assertStringContainsString('Hydrating 8,000 entities', $enhanced);
        self::assertStringNotContainsString('Memory usage:', $enhanced);
        self::assertStringContainsString('batch processing', $enhanced);
    }

    #[Test]
    public function it_enhances_frequent_query_message_with_high_frequency(): void
    {
        $enhanced = $this->enhancer->enhanceFrequentQueryMessage(
            'Frequent query detected',
            150,
            120.0,
            0.8,
        );

        self::assertStringContainsString('Executed 150 times', $enhanced);
        self::assertStringContainsString('120.00ms total', $enhanced);
        self::assertStringContainsString('0.80ms average', $enhanced);
        self::assertStringContainsString('HIGH', $enhanced);
        self::assertStringContainsString('query result caching', $enhanced);
    }

    #[Test]
    public function it_enhances_frequent_query_message_with_low_impact(): void
    {
        $enhanced = $this->enhancer->enhanceFrequentQueryMessage(
            'Frequent query detected',
            30,
            15.0,
            0.5,
        );

        self::assertStringContainsString('LOW', $enhanced);
        self::assertStringContainsString('Frequent but low impact', $enhanced);
    }
}
