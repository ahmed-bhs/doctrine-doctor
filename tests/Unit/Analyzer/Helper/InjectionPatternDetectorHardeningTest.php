<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Helper;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\InjectionPatternDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InjectionPatternDetectorHardeningTest extends TestCase
{
    private InjectionPatternDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new InjectionPatternDetector();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tautologyBypassProvider(): iterable
    {
        yield 'OR 2=2'           => ["SELECT * FROM users WHERE id = 1 OR 2=2"];
        yield 'OR true'          => ["SELECT * FROM users WHERE id = 1 OR TRUE"];
        yield 'OR comment bypass' => ["SELECT * FROM users WHERE id = 1 OR/**/1=1"];
        yield 'OR with quotes'   => ["SELECT * FROM users WHERE id = '1' OR '1'='1'"];
        yield 'AND 5=5'          => ["SELECT * FROM users WHERE id = 1 AND 5=5"];
    }

    #[Test]
    #[DataProvider('tautologyBypassProvider')]
    public function it_detects_tautology_injection_variants(string $sql): void
    {
        self::assertTrue(
            $this->detector->hasSQLInjectionKeywords($sql),
            sprintf('Tautology should be detected: %s', $sql),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unionBypassProvider(): iterable
    {
        yield 'UNION SELECT'        => ['SELECT id FROM users UNION SELECT password FROM admins'];
        yield 'UNION ALL SELECT'    => ['SELECT id FROM users UNION ALL SELECT password FROM admins'];
        yield 'UNION newline SELECT' => ["SELECT id FROM users UNION\nSELECT password FROM admins"];
        yield 'UNION tab SELECT'    => ["SELECT id FROM users UNION\tSELECT password FROM admins"];
    }

    #[Test]
    #[DataProvider('unionBypassProvider')]
    public function it_detects_union_injection_variants(string $sql): void
    {
        self::assertTrue(
            $this->detector->hasSQLInjectionKeywords($sql),
            sprintf('UNION variant should be detected: %s', $sql),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function limitOffsetInjectionProvider(): iterable
    {
        yield 'LIMIT quoted'          => ["SELECT * FROM users LIMIT '10'"];
        yield 'OFFSET quoted'         => ["SELECT * FROM users LIMIT 10 OFFSET '5'"];
        yield 'LIMIT with statement'  => ['SELECT * FROM users LIMIT 1; DROP TABLE users'];
        yield 'LIMIT non-numeric'     => ['SELECT * FROM users LIMIT abc'];
    }

    #[Test]
    #[DataProvider('limitOffsetInjectionProvider')]
    public function it_detects_limit_offset_injection(string $sql): void
    {
        self::assertTrue(
            $this->detector->hasSuspiciousLimitOrOffset($sql),
            sprintf('LIMIT/OFFSET injection should be detected: %s', $sql),
        );
    }

    #[Test]
    public function it_does_not_flag_normal_limit_offset(): void
    {
        self::assertFalse($this->detector->hasSuspiciousLimitOrOffset('SELECT * FROM users LIMIT 10'));
        self::assertFalse($this->detector->hasSuspiciousLimitOrOffset('SELECT * FROM users LIMIT 10 OFFSET 5'));
        self::assertFalse($this->detector->hasSuspiciousLimitOrOffset('SELECT * FROM users LIMIT ?'));
        self::assertFalse($this->detector->hasSuspiciousLimitOrOffset('SELECT * FROM users LIMIT :max'));
    }
}
