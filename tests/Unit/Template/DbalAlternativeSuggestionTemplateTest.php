<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Template;

use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * When the code does not need managed entities, DBAL is the lighter tool: one
 * set-based statement instead of a write per entity, plain rows instead of
 * hydrated objects. The suggestions offer it alongside the ORM fix and say what
 * it bypasses.
 */
final class DbalAlternativeSuggestionTemplateTest extends TestCase
{
    private PhpTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new PhpTemplateRenderer(\dirname(__DIR__, 3) . '/src/Template/Suggestions', new NullLogger());
    }

    #[Test]
    public function it_offers_a_single_set_based_update_for_repeated_updates(): void
    {
        $code = $this->renderer->render('Performance/batch_operation', ['table' => 'orders', 'operation_count' => 40, 'operation_type' => 'UPDATE'])['code'];

        self::assertStringContainsString('executeStatement(', $code);
        self::assertStringContainsString('UPDATE orders SET', $code);
        self::assertStringContainsString('lifecycle', $code);
        self::assertStringContainsString('$em-&gt;clear()', $code);
    }

    #[Test]
    public function it_offers_a_single_set_based_delete_for_repeated_deletes(): void
    {
        $code = $this->renderer->render('Performance/batch_operation', ['table' => 'orders', 'operation_count' => 40, 'operation_type' => 'DELETE'])['code'];

        self::assertStringContainsString('DELETE FROM orders WHERE', $code);
        self::assertStringNotContainsString('UPDATE orders SET', $code);
    }

    #[Test]
    public function it_keeps_rendering_without_the_operation_type(): void
    {
        $code = $this->renderer->render('Performance/batch_operation', ['table' => 'orders', 'operation_count' => 40])['code'];

        self::assertStringContainsString('UPDATE orders SET', $code);
    }

    #[Test]
    public function it_offers_dbal_rows_for_aggregations(): void
    {
        $code = $this->renderer->render('Performance/dto_hydration', ['query_count' => 2, 'aggregations' => ['SUM']])['code'];

        self::assertStringContainsString('SELECT NEW', $code);
        self::assertStringContainsString('fetchAllAssociative(', $code);
    }

    #[Test]
    public function it_fixes_query_builder_injection_with_set_parameter(): void
    {
        $code = $this->renderer->render('Security/sql_injection', ['layer' => 'orm'])['code'];

        self::assertStringContainsString('setParameter(', $code);
        self::assertStringNotContainsString('executeQuery(', $code);
    }

    #[Test]
    public function it_fixes_raw_sql_injection_with_the_dbal_4_parameter_api(): void
    {
        $code = $this->renderer->render('Security/sql_injection', ['layer' => 'dbal'])['code'];

        self::assertStringContainsString('ParameterType::INTEGER', $code);
        self::assertStringNotContainsString('PDO::PARAM', $code);
    }
}
