<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\ValueObject;

use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    #[Test]
    public function it_creates_all_three_severity_levels(): void
    {
        $critical = Severity::critical();
        $warning = Severity::warning();
        $info = Severity::info();

        self::assertSame('critical', $critical->getValue());
        self::assertSame('warning', $warning->getValue());
        self::assertSame('info', $info->getValue());
    }

    #[Test]
    public function it_calculates_correct_priority(): void
    {
        self::assertSame(3, Severity::critical()->getPriority());
        self::assertSame(2, Severity::warning()->getPriority());
        self::assertSame(1, Severity::info()->getPriority());
    }

    #[Test]
    public function it_compares_severity_levels_correctly(): void
    {
        $critical = Severity::critical();
        $warning = Severity::warning();
        $info = Severity::info();

        // Critical > Warning
        self::assertTrue($critical->isHigherThan($warning));
        self::assertFalse($warning->isHigherThan($critical));

        // Warning > Info
        self::assertTrue($warning->isHigherThan($info));
        self::assertTrue($info->isLowerThan($warning));

        // Critical > Info
        self::assertTrue($critical->isHigherThan($info));
        self::assertTrue($info->isLowerThan($critical));
    }

    #[Test]
    public function it_provides_correct_emojis(): void
    {
        self::assertSame('🔴', Severity::critical()->getEmoji());
        self::assertSame('🟠', Severity::warning()->getEmoji());
        self::assertSame('🔵', Severity::info()->getEmoji());
    }

    #[Test]
    public function it_provides_correct_colors(): void
    {
        self::assertSame('red', Severity::critical()->getColor());
        self::assertSame('yellow', Severity::warning()->getColor());
        self::assertSame('lightblue', Severity::info()->getColor());
    }

    #[Test]
    public function it_compares_using_spaceship_operator(): void
    {
        $critical = Severity::critical();
        $warning = Severity::warning();
        $info = Severity::info();

        self::assertGreaterThan(0, $critical->compareTo($warning));
        self::assertLessThan(0, $info->compareTo($warning));
        self::assertSame(0, $warning->compareTo(Severity::warning()));
    }

    #[Test]
    public function it_has_correct_type_checking_methods(): void
    {
        $critical = Severity::critical();
        self::assertTrue($critical->isCritical());
        self::assertFalse($critical->isWarning());
        self::assertFalse($critical->isInfo());

        $warning = Severity::warning();
        self::assertTrue($warning->isWarning());
        self::assertFalse($warning->isCritical());
        self::assertFalse($warning->isInfo());

        $info = Severity::info();
        self::assertTrue($info->isInfo());
        self::assertFalse($info->isCritical());
        self::assertFalse($info->isWarning());
    }

    #[Test]
    public function it_creates_from_string(): void
    {
        self::assertSame(Severity::critical(), Severity::fromString('critical'));
        self::assertSame(Severity::warning(), Severity::fromString('warning'));
        self::assertSame(Severity::info(), Severity::fromString('info'));
    }
}
