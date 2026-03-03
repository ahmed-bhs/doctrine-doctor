<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Utils;

use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use PHPUnit\Framework\TestCase;

final class DescriptionHighlighterTest extends TestCase
{
    public function test_code_helper_keeps_apostrophes_readable(): void
    {
        $html = DescriptionHighlighter::code("User's status");

        self::assertSame("<code>User's status</code>", $html);
    }
}
