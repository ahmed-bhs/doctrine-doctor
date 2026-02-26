<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Helper;

use AhmedBhs\DoctrineDoctor\Helper\ClassNameHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClassNameHelperTest extends TestCase
{
    #[Test]
    public function it_extracts_short_name_from_fqcn(): void
    {
        self::assertSame('User', ClassNameHelper::shortName('App\\Entity\\User'));
    }

    #[Test]
    public function it_returns_same_value_for_non_namespaced_class(): void
    {
        self::assertSame('User', ClassNameHelper::shortName('User'));
    }

    #[Test]
    public function it_returns_empty_string_for_empty_input(): void
    {
        self::assertSame('', ClassNameHelper::shortName(''));
    }
}
