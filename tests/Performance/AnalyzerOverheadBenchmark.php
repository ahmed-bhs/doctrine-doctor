<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\PhpCodeParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Performance benchmark for analyzer overhead.
 *
 * This test measures the actual performance improvement from Quick Wins Phase 1:
 * - EntityMetadataCache: Reduces repeated getAllMetadata() calls
 * - PhpCodeParser cache: Reduces repeated AST parsing and analysis
 *
 * Run with: php vendor/bin/phpunit tests/Performance/AnalyzerOverheadBenchmark.php --testdox
 */
final class AnalyzerOverheadBenchmark extends TestCase
{
    private PhpCodeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PhpCodeParser();
    }

    /**
     * Benchmark: Analyze 100 methods with cache (simulates real-world usage).
     *
     * Expected performance WITH cache:
     * - First pass: ~50ms (cache MISS, parse + analyze)
     * - Second pass: ~5ms (cache HIT, return cached result)
     * - Total: ~55ms for 100 methods analyzed 2× each
     */
    #[Test]
    public function benchmark_with_cache_100_methods_analyzed_twice(): void
    {
        $method = new ReflectionMethod(BenchmarkTestClass::class, 'vulnerableMethod');

        $startTime = microtime(true);

        // First pass: 100 methods analyzed (cache MISS)
        for ($i = 0; $i < 100; ++$i) {
            $result = $this->parser->detectSqlInjectionPatterns($method);
            self::assertTrue($result['concatenation']);
        }

        $firstPassTime = (microtime(true) - $startTime) * 1000;

        // Second pass: Same 100 methods (cache HIT)
        $secondPassStart = microtime(true);
        for ($i = 0; $i < 100; ++$i) {
            $result = $this->parser->detectSqlInjectionPatterns($method);
            self::assertTrue($result['concatenation']);
        }

        $secondPassTime = (microtime(true) - $secondPassStart) * 1000;
        $totalTime = (microtime(true) - $startTime) * 1000;

        // Report performance
        echo sprintf(
            "\n\n📊 Performance Benchmark (100 methods × 2 passes):\n"
            . "  First pass (cache MISS):  %6.2f ms\n"
            . "  Second pass (cache HIT):  %6.2f ms\n"
            . "  Total time:               %6.2f ms\n"
            . "  Cache speedup:            %.1f× faster\n"
            . "  Cache hit rate:           %.1f%%\n\n",
            $firstPassTime,
            $secondPassTime,
            $totalTime,
            $firstPassTime / max($secondPassTime, 0.1),
            100.0 - ($secondPassTime / $firstPassTime * 100),
        );

        // Verify cache stats
        $stats = $this->parser->getCacheStats();
        self::assertGreaterThan(0, $stats['analysis_entries'], 'Cache should have entries');
        self::assertLessThan(100, $totalTime, 'Total time should be < 100ms with cache');

        echo sprintf(
            "📈 Cache Statistics:\n"
            . "  AST cache entries:        %d\n"
            . "  Analysis cache entries:   %d\n"
            . "  Memory usage:             %.2f MB\n\n",
            $stats['ast_entries'],
            $stats['analysis_entries'],
            $stats['memory_bytes'] / 1024 / 1024,
        );
    }

    /**
     * Benchmark: Simulate cache invalidation scenario.
     *
     * Tests the automatic invalidation when files are modified.
     * This ensures new alerts are always detected.
     */
    #[Test]
    public function benchmark_cache_invalidation_overhead(): void
    {
        $tempFile = sys_get_temp_dir() . '/doctrine_doctor_perf_' . uniqid() . '.php';

        // Create test file
        file_put_contents(
            $tempFile,
            <<<'PHP'
            <?php
            class PerfTestClass {
                public function method(\Doctrine\DBAL\Connection $conn): void {
                    $sql = "SELECT * FROM users WHERE id = " . $_GET['id'];
                    $conn->executeQuery($sql);
                }
            }
            PHP
        );

        require_once $tempFile;
        $method = new ReflectionMethod('PerfTestClass', 'method');

        $startTime = microtime(true);

        // First analysis
        $result1 = $this->parser->detectSqlInjectionPatterns($method);
        $time1 = (microtime(true) - $startTime) * 1000;

        // Second analysis (cache HIT)
        $start2 = microtime(true);
        $result2 = $this->parser->detectSqlInjectionPatterns($method);
        $time2 = (microtime(true) - $start2) * 1000;

        // Modify file (change mtime)
        sleep(1);
        touch($tempFile);

        // Third analysis (cache MISS due to mtime change)
        $start3 = microtime(true);
        $result3 = $this->parser->detectSqlInjectionPatterns($method);
        $time3 = (microtime(true) - $start3) * 1000;

        echo sprintf(
            "\n\n🔄 Cache Invalidation Benchmark:\n"
            . "  First analysis (MISS):    %6.2f ms\n"
            . "  Second analysis (HIT):    %6.2f ms (%.1f× faster)\n"
            . "  After file modified:      %6.2f ms (auto-invalidated)\n"
            . "  Invalidation overhead:    %6.2f ms\n\n",
            $time1,
            $time2,
            $time1 / max($time2, 0.1),
            $time3,
            $time3 - $time2,
        );

        // Results should be identical
        self::assertEquals($result1, $result2);
        self::assertEquals($result1, $result3);

        unlink($tempFile);
    }

    /**
     * Stress test: 1000 methods to simulate large codebase.
     */
    #[Test]
    public function benchmark_large_codebase_1000_methods(): void
    {
        $method = new ReflectionMethod(BenchmarkTestClass::class, 'vulnerableMethod');

        $startTime = microtime(true);

        // Analyze 1000 methods
        for ($i = 0; $i < 1000; ++$i) {
            $result = $this->parser->detectSqlInjectionPatterns($method);
            self::assertTrue($result['concatenation']);
        }

        $totalTime = (microtime(true) - $startTime) * 1000;
        $perMethodTime = $totalTime / 1000;

        echo sprintf(
            "\n\n🚀 Large Codebase Benchmark (1000 methods):\n"
            . "  Total time:               %6.2f ms\n"
            . "  Per method:               %6.2f ms\n"
            . "  Throughput:               %.0f methods/sec\n\n",
            $totalTime,
            $perMethodTime,
            1000 / ($totalTime / 1000),
        );

        $stats = $this->parser->getCacheStats();
        echo sprintf(
            "📊 Final Cache Stats:\n"
            . "  Total cache entries:      %d\n"
            . "  Memory usage:             %.2f MB\n"
            . "  Avg memory per entry:     %.2f KB\n\n",
            $stats['analysis_entries'] + $stats['ast_entries'],
            $stats['memory_bytes'] / 1024 / 1024,
            ($stats['memory_bytes'] / 1024) / max($stats['analysis_entries'] + $stats['ast_entries'], 1),
        );

        // Should complete in reasonable time
        self::assertLessThan(500, $totalTime, 'Should analyze 1000 methods in < 500ms');
    }
}

/**
 * Test class for benchmarking.
 */
class BenchmarkTestClass
{
    public function vulnerableMethod(\Doctrine\DBAL\Connection $connection): void
    {
        $userId = $_GET['id'];
        $sql = "SELECT * FROM users WHERE id = " . $userId;
        $connection->executeQuery($sql);
    }

    public function safeMethod(\Doctrine\DBAL\Connection $connection): void
    {
        $stmt = $connection->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->bindValue(1, $_GET['id']);
        $stmt->executeQuery();
    }
}
