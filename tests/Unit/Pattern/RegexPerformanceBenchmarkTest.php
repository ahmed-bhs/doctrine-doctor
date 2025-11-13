<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Pattern;

use PHPUnit\Framework\TestCase;

/**
 * Performance benchmark: Regex vs str_contains()
 */
class RegexPerformanceBenchmarkTest extends TestCase
{
    private const ITERATIONS = 10000;

    public function testRegexVsStrContainsPerformance(): void
    {
        $sql = 'SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id ORDER BY u.created_at';
        $keyword = 'ORDER BY';

        // Benchmark regex
        $startRegex = microtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            preg_match('/ORDER BY/i', $sql);
        }
        $regexTime = microtime(true) - $startRegex;

        // Benchmark str_contains
        $startStrContains = microtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            str_contains(strtoupper($sql), $keyword);
        }
        $strContainsTime = microtime(true) - $startStrContains;

        // Performance should be comparable (within 50% of each other)
        // We use str_contains for READABILITY, not necessarily raw performance
        $maxTime = max($regexTime, $strContainsTime);
        $minTime = min($regexTime, $strContainsTime);
        $ratio = $maxTime / $minTime;

        $this->assertLessThanOrEqual(
            1.5,
            $ratio,
            sprintf(
                'Performance should be comparable (ratio: %.2fx). Regex: %fms, str_contains: %fms',
                $ratio,
                $regexTime * 1000,
                $strContainsTime * 1000
            )
        );

        // Determine which is faster
        $winner = $strContainsTime < $regexTime ? 'str_contains' : 'regex';
        $speedup = $strContainsTime < $regexTime
            ? $regexTime / $strContainsTime
            : $strContainsTime / $regexTime;

        // Output for information
        fwrite(STDOUT, sprintf(
            "\n📊 Performance Benchmark (" . self::ITERATIONS . " iterations):\n" .
            "   - Regex:        %.6f seconds\n" .
            "   - str_contains: %.6f seconds\n" .
            "   - Winner:       %s (%.2fx faster)\n" .
            "   \n" .
            "   ✅ Both methods have comparable performance.\n" .
            "   💡 str_contains() is chosen for READABILITY, not raw speed.\n",
            $regexTime,
            $strContainsTime,
            $winner,
            $speedup
        ));
    }
}
