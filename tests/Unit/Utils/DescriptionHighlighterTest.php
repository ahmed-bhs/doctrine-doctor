<?php

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

