<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\ValueObject;

use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionContentBlock;
use PHPUnit\Framework\TestCase;

final class SuggestionContentBlockTest extends TestCase
{
    public function test_code_block_preserves_apostrophes(): void
    {
        $block = SuggestionContentBlock::code("return 'ok';", 'php');

        self::assertStringContainsString("return 'ok';", $block->toHtml());
        self::assertStringNotContainsString('&#039;', $block->toHtml());
        self::assertStringNotContainsString('&apos;', $block->toHtml());
    }

    public function test_comparison_block_preserves_apostrophes(): void
    {
        $block = SuggestionContentBlock::comparison("echo 'bad';", "echo 'good';", 'php');

        self::assertStringContainsString("echo 'bad';", $block->toHtml());
        self::assertStringContainsString("echo 'good';", $block->toHtml());
        self::assertStringNotContainsString('&#039;', $block->toHtml());
        self::assertStringNotContainsString('&apos;', $block->toHtml());
    }
}
