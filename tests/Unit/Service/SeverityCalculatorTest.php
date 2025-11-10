<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Service;

use AhmedBhs\DoctrineDoctor\Service\SeverityCalculator;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SeverityCalculator.
 */
final class SeverityCalculatorTest extends TestCase
{
    private SeverityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new SeverityCalculator();
    }

    #[Test]
    #[DataProvider('nPlusOneSeverityProvider')]
    public function it_calculates_n_plus_one_severity_correctly(
        int $queryCount,
        float $totalTime,
        Severity $expectedSeverity,
    ): void {
        $severity = $this->calculator->calculateNPlusOneSeverity($queryCount, $totalTime);

        self::assertSame($expectedSeverity, $severity);
    }

    public static function nPlusOneSeverityProvider(): array
    {
        return [
            // Critical cases
            'critical_high_query_count' => [200, 50.0, Severity::CRITICAL],
            'critical_high_time' => [50, 150.0, Severity::CRITICAL],
            'critical_both_high' => [150, 120.0, Severity::CRITICAL],

            // Warning cases
            'warning_moderate_queries' => [15, 15.0, Severity::WARNING],
            'warning_just_above_threshold' => [11, 5.0, Severity::WARNING],

            // Info cases
            'info_low_queries' => [5, 3.0, Severity::INFO],
            'info_just_below_threshold' => [10, 9.0, Severity::INFO],
        ];
    }

    #[Test]
    #[DataProvider('missingIndexSeverityProvider')]
    public function it_calculates_missing_index_severity_correctly(
        int $rowsScanned,
        float $queryTime,
        Severity $expectedSeverity,
    ): void {
        $severity = $this->calculator->calculateMissingIndexSeverity($rowsScanned, $queryTime);

        self::assertSame($expectedSeverity, $severity);
    }

    public static function missingIndexSeverityProvider(): array
    {
        return [
            // Critical cases
            'critical_huge_table_scan' => [150000, 50.0, Severity::CRITICAL],
            'critical_slow_query' => [50000, 150.0, Severity::CRITICAL],

            // Warning cases
            'warning_medium_scan' => [5000, 15.0, Severity::WARNING],
            'warning_just_above_threshold' => [1001, 5.0, Severity::WARNING],

            // Info cases
            'info_small_scan' => [500, 3.0, Severity::INFO],
            'info_just_below_threshold' => [1000, 9.0, Severity::INFO],
        ];
    }

    #[Test]
    #[DataProvider('slowQuerySeverityProvider')]
    public function it_calculates_slow_query_severity_correctly(
        float $queryTime,
        Severity $expectedSeverity,
    ): void {
        $severity = $this->calculator->calculateSlowQuerySeverity($queryTime);

        self::assertSame($expectedSeverity, $severity);
    }

    public static function slowQuerySeverityProvider(): array
    {
        return [
            'critical_very_slow' => [150.0, Severity::CRITICAL],
            'critical_just_above_threshold' => [101.0, Severity::CRITICAL],
            'warning_moderate' => [50.0, Severity::WARNING],
            'warning_just_above_threshold' => [11.0, Severity::WARNING],
            'info_fast' => [5.0, Severity::INFO],
            'info_just_below_threshold' => [10.0, Severity::INFO],
        ];
    }

    #[Test]
    #[DataProvider('hydrationSeverityProvider')]
    public function it_calculates_hydration_severity_correctly(
        int $rowCount,
        ?int $memoryUsage,
        Severity $expectedSeverity,
    ): void {
        $severity = $this->calculator->calculateHydrationSeverity($rowCount, $memoryUsage);

        self::assertSame($expectedSeverity, $severity);
    }

    public static function hydrationSeverityProvider(): array
    {
        return [
            'critical_huge_dataset' => [15000, null, Severity::CRITICAL],
            'critical_high_memory' => [5000, 60 * 1024 * 1024, Severity::CRITICAL],
            'warning_moderate_dataset' => [3000, null, Severity::WARNING],
            'warning_moderate_memory' => [500, 15 * 1024 * 1024, Severity::WARNING],
            'info_small_dataset' => [500, null, Severity::INFO],
            'info_low_memory' => [200, 5 * 1024 * 1024, Severity::INFO],
        ];
    }

    #[Test]
    #[DataProvider('shouldSuppressProvider')]
    public function it_determines_suppression_correctly(
        string $issueType,
        array $metrics,
        bool $expectedSuppression,
    ): void {
        $shouldSuppress = $this->calculator->shouldSuppress($issueType, $metrics);

        self::assertSame($expectedSuppression, $shouldSuppress);
    }

    /**
     * @return array<string, array{string, array<string, int|float>, bool}>
     */
    public static function shouldSuppressProvider(): array
    {
        return [
            // Slow query
            'suppress_very_fast_query' => ['slow_query', ['time' => 5.0], true],
            'keep_slow_query' => ['slow_query', ['time' => 15.0], false],
            'suppress_threshold_query' => ['slow_query', ['time' => 9.0], true],

            // Missing index
            'suppress_small_scan' => ['missing_index', ['rows_scanned' => 100], true],
            'keep_medium_scan' => ['missing_index', ['rows_scanned' => 1000], false],
            'suppress_threshold_scan' => ['missing_index', ['rows_scanned' => 499], true],

            // N+1
            'suppress_tiny_n_plus_one' => ['n_plus_one', ['count' => 2], true],
            'keep_n_plus_one' => ['n_plus_one', ['count' => 5], false],

            // Frequent query
            'suppress_few_executions' => ['frequent_query', ['count' => 5], true],
            'keep_frequent' => ['frequent_query', ['count' => 15], false],

            // ORDER BY without LIMIT
            'suppress_small_order_by' => ['order_by_without_limit', ['rows' => 30], true],
            'keep_large_order_by' => ['order_by_without_limit', ['rows' => 100], false],

            // findAll
            'suppress_small_find_all' => ['find_all', ['rows' => 50], true],
            'keep_large_find_all' => ['find_all', ['rows' => 150], false],
        ];
    }

    #[Test]
    public function it_calculates_frequent_query_severity_correctly(): void
    {
        $criticalSeverity = $this->calculator->calculateFrequentQuerySeverity(150, 50.0);
        self::assertSame(Severity::CRITICAL, $criticalSeverity);

        $warningSeverity = $this->calculator->calculateFrequentQuerySeverity(30, 25.0);
        self::assertSame(Severity::WARNING, $warningSeverity);

        $infoSeverity = $this->calculator->calculateFrequentQuerySeverity(10, 5.0);
        self::assertSame(Severity::INFO, $infoSeverity);
    }

    #[Test]
    public function it_calculates_order_by_without_limit_severity_correctly(): void
    {
        $criticalSeverity = $this->calculator->calculateOrderByWithoutLimitSeverity(15000, 50.0);
        self::assertSame(Severity::CRITICAL, $criticalSeverity);

        $warningSeverity = $this->calculator->calculateOrderByWithoutLimitSeverity(500, 60.0);
        self::assertSame(Severity::WARNING, $warningSeverity);

        $infoSeverity = $this->calculator->calculateOrderByWithoutLimitSeverity(50, 5.0);
        self::assertSame(Severity::INFO, $infoSeverity);
    }

    #[Test]
    public function it_calculates_find_all_severity_correctly(): void
    {
        $criticalSeverity = $this->calculator->calculateFindAllSeverity(15000, 50.0);
        self::assertSame(Severity::CRITICAL, $criticalSeverity);

        $warningSeverity = $this->calculator->calculateFindAllSeverity(500, 60.0);
        self::assertSame(Severity::WARNING, $warningSeverity);

        $infoSeverity = $this->calculator->calculateFindAllSeverity(50, 5.0);
        self::assertSame(Severity::INFO, $infoSeverity);
    }
}
