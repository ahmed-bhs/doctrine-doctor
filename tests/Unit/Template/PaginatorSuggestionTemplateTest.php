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
 * ORM 3.7 deprecates Doctrine\ORM\Tools\Pagination\Paginator in favour of
 * OffsetPaginator, and ships a CursorPaginator for keyset pagination. The
 * suggestions point to them when installed and keep working on older ORM versions.
 */
final class PaginatorSuggestionTemplateTest extends TestCase
{
    private PhpTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new PhpTemplateRenderer(\dirname(__DIR__, 3) . '/src/Template/Suggestions', new NullLogger());
    }

    #[Test]
    public function it_recommends_offset_paginator_when_available(): void
    {
        $code = $this->render(true);

        self::assertStringContainsString('OffsetPaginator', $code);
        self::assertStringContainsString('new Window(', $code);
        self::assertStringNotContainsString('new Paginator(', $code);
    }

    #[Test]
    public function it_falls_back_to_paginator_before_orm_3_7(): void
    {
        $code = $this->render(false);

        self::assertStringContainsString('new Paginator(', $code);
        self::assertStringNotContainsString('OffsetPaginator', $code);
    }

    #[Test]
    public function it_falls_back_to_paginator_when_the_context_key_is_missing(): void
    {
        $result = $this->renderer->render('Performance/setMaxResults_with_collection_join', ['entity_hint' => 'Order']);

        self::assertStringContainsString('new Paginator(', $result['code']);
    }

    #[Test]
    public function it_recommends_cursor_paginator_for_deep_offsets_when_available(): void
    {
        $code = $this->renderDeepOffset(true);

        self::assertStringContainsString('CursorPaginator', $code);
        self::assertStringContainsString('getNextCursorAsString()', $code);
    }

    #[Test]
    public function it_falls_back_to_manual_keyset_pagination_before_orm_3_7(): void
    {
        $code = $this->renderDeepOffset(false);

        self::assertStringNotContainsString('CursorPaginator', $code);
        self::assertStringContainsString(':lastId', $code);
    }

    private function renderDeepOffset(bool $cursorPaginatorAvailable): string
    {
        return $this->renderer->render('Performance/deep_offset_pagination', [
            'offset'                      => 5000,
            'original_query'              => 'SELECT * FROM users ORDER BY id LIMIT 20 OFFSET 5000',
            'cursor_paginator_available'  => $cursorPaginatorAvailable,
        ])['code'];
    }

    private function render(bool $offsetPaginatorAvailable): string
    {
        return $this->renderer->render('Performance/setMaxResults_with_collection_join', [
            'entity_hint'                 => 'Order',
            'offset_paginator_available'  => $offsetPaginatorAvailable,
        ])['code'];
    }
}
