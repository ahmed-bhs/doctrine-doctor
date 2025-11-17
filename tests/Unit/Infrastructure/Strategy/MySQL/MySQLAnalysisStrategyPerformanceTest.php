<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Infrastructure\Strategy\MySQL;

use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\MySQL\MySQLAnalysisStrategy;
use AhmedBhs\DoctrineDoctor\Template\Renderer\TwigTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Unit tests for MySQLAnalysisStrategy::analyzePerformanceConfig().
 */
final class MySQLAnalysisStrategyPerformanceTest extends TestCase
{
    #[Test]
    public function it_detects_query_cache_enabled(): void
    {
        $connection = $this->createMock(Connection::class);
        $detector = $this->createMock(DatabasePlatformDetector::class);

        // Mock: query_cache_type = ON
        $result = $this->createMock(Result::class);
        $connection->expects(self::any())
            ->method('executeQuery')
            ->willReturnCallback(function ($sql) use ($result) {
                if (str_contains($sql, 'query_cache_type')) {
                    return $result;
                }
                return $this->createDefaultResult();
            });

        $detector->expects(self::any())
            ->method('fetchAssociative')
            ->willReturnCallback(function ($res) use ($result) {
                if ($res === $result) {
                    return ['Value' => 'ON'];
                }
                return $this->getDefaultValues();
            });

        $strategy = new MySQLAnalysisStrategy(
            $connection,
            $this->createSuggestionFactory(),
            $detector,
        );

        /** @var \Traversable<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface> $result */
        $result = $strategy->analyzePerformanceConfig();
        $issues = iterator_to_array($result);

        // Should detect query cache enabled
        $queryCacheIssues = array_filter(
            $issues,
            fn ($issue) =>
            str_contains($issue->getTitle(), 'Query Cache'),
        );

        self::assertCount(1, $queryCacheIssues, 'Should detect query cache enabled');
        $issue = reset($queryCacheIssues);
        self::assertEquals('critical', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_ignores_query_cache_when_off(): void
    {
        $connection = $this->createMock(Connection::class);
        $detector = $this->createMock(DatabasePlatformDetector::class);

        // Mock: query_cache_type = OFF
        $this->mockShowVariables($connection, $detector, [
            'query_cache_type' => 'OFF',
            'innodb_flush_log_at_trx_commit' => '2',
            'log_bin' => 'OFF',
            'innodb_buffer_pool_size' => '536870912', // 512MB
        ]);

        $strategy = new MySQLAnalysisStrategy(
            $connection,
            $this->createSuggestionFactory(),
            $detector,
        );

        /** @var \Traversable<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface> $result */
        $result = $strategy->analyzePerformanceConfig();
        $issues = iterator_to_array($result);

        // Should NOT detect query cache issue
        $queryCacheIssues = array_filter(
            $issues,
            fn ($issue) =>
            str_contains($issue->getTitle(), 'Query Cache'),
        );

        self::assertCount(0, $queryCacheIssues, 'Should not detect query cache when OFF');
    }

    #[Test]
    public function it_detects_innodb_flush_log_at_trx_commit_1(): void
    {
        $connection = $this->createMock(Connection::class);
        $detector = $this->createMock(DatabasePlatformDetector::class);

        $this->mockShowVariables($connection, $detector, [
            'query_cache_type' => 'OFF',
            'innodb_flush_log_at_trx_commit' => '1',
            'log_bin' => 'OFF',
            'innodb_buffer_pool_size' => '536870912',
        ]);

        $strategy = new MySQLAnalysisStrategy(
            $connection,
            $this->createSuggestionFactory(),
            $detector,
        );

        /** @var \Traversable<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface> $result */
        $result = $strategy->analyzePerformanceConfig();
        $issues = iterator_to_array($result);

        $flushLogIssues = array_filter(
            $issues,
            fn ($issue) =>
            str_contains($issue->getTitle(), 'InnoDB full ACID'),
        );

        self::assertCount(1, $flushLogIssues, 'Should detect innodb_flush_log_at_trx_commit = 1');
        $issue = reset($flushLogIssues);
        self::assertEquals('info', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_binary_log_enabled(): void
    {
        $connection = $this->createMock(Connection::class);
        $detector = $this->createMock(DatabasePlatformDetector::class);

        $this->mockShowVariables($connection, $detector, [
            'query_cache_type' => 'OFF',
            'innodb_flush_log_at_trx_commit' => '2',
            'log_bin' => 'ON',
            'innodb_buffer_pool_size' => '536870912',
        ]);

        $strategy = new MySQLAnalysisStrategy(
            $connection,
            $this->createSuggestionFactory(),
            $detector,
        );

        /** @var \Traversable<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface> $result */
        $result = $strategy->analyzePerformanceConfig();
        $issues = iterator_to_array($result);

        $binlogIssues = array_filter(
            $issues,
            fn ($issue) =>
            str_contains($issue->getTitle(), 'Binary logging'),
        );

        self::assertCount(1, $binlogIssues, 'Should detect binary logging enabled');
        $issue = reset($binlogIssues);
        self::assertEquals('info', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_buffer_pool_too_small(): void
    {
        $connection = $this->createMock(Connection::class);
        $detector = $this->createMock(DatabasePlatformDetector::class);

        $this->mockShowVariables($connection, $detector, [
            'query_cache_type' => 'OFF',
            'innodb_flush_log_at_trx_commit' => '2',
            'log_bin' => 'OFF',
            'innodb_buffer_pool_size' => '50331648', // 48MB < 64MB
        ]);

        $strategy = new MySQLAnalysisStrategy(
            $connection,
            $this->createSuggestionFactory(),
            $detector,
        );

        /** @var \Traversable<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface> $result */
        $result = $strategy->analyzePerformanceConfig();
        $issues = iterator_to_array($result);

        $bufferPoolIssues = array_filter(
            $issues,
            fn ($issue) =>
            str_contains($issue->getTitle(), 'buffer pool'),
        );

        self::assertCount(1, $bufferPoolIssues, 'Should detect buffer pool < 128MB');
        $issue = reset($bufferPoolIssues);
        self::assertEquals('warning', $issue->getSeverity()->value); // < 64MB = warning
    }

    #[Test]
    public function it_detects_buffer_pool_very_small(): void
    {
        $connection = $this->createMock(Connection::class);
        $detector = $this->createMock(DatabasePlatformDetector::class);

        $this->mockShowVariables($connection, $detector, [
            'query_cache_type' => 'OFF',
            'innodb_flush_log_at_trx_commit' => '2',
            'log_bin' => 'OFF',
            'innodb_buffer_pool_size' => '100663296', // 96MB (between 64MB and 128MB)
        ]);

        $strategy = new MySQLAnalysisStrategy(
            $connection,
            $this->createSuggestionFactory(),
            $detector,
        );

        /** @var \Traversable<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface> $result */
        $result = $strategy->analyzePerformanceConfig();
        $issues = iterator_to_array($result);

        $bufferPoolIssues = array_filter(
            $issues,
            fn ($issue) =>
            str_contains($issue->getTitle(), 'buffer pool'),
        );

        self::assertCount(1, $bufferPoolIssues, 'Should detect buffer pool < 128MB');
        $issue = reset($bufferPoolIssues);
        self::assertEquals('info', $issue->getSeverity()->value); // >= 64MB = info
    }

    #[Test]
    public function it_returns_no_issues_when_all_configs_optimal(): void
    {
        $connection = $this->createMock(Connection::class);
        $detector = $this->createMock(DatabasePlatformDetector::class);

        $this->mockShowVariables($connection, $detector, [
            'query_cache_type' => 'OFF',
            'innodb_flush_log_at_trx_commit' => '2',
            'log_bin' => 'OFF',
            'innodb_buffer_pool_size' => '536870912', // 512MB
        ]);

        $strategy = new MySQLAnalysisStrategy(
            $connection,
            $this->createSuggestionFactory(),
            $detector,
        );

        /** @var \Traversable<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface> $result */
        $result = $strategy->analyzePerformanceConfig();
        $issues = iterator_to_array($result);

        self::assertCount(0, $issues, 'Should return no issues when all configs are optimal');
    }

    private function mockShowVariables(Connection $connection, DatabasePlatformDetector $detector, array $values): void
    {
        /** @phpstan-ignore-next-line Mock object has expects() method */
        $connection->expects(self::any())
            ->method('executeQuery')
            ->willReturnCallback(function () {
                $result = $this->createMock(Result::class);
                return $result;
            });

        /** @phpstan-ignore-next-line Mock object has expects() method */
        $detector->expects(self::any())
            ->method('fetchAssociative')
            ->willReturnCallback(function () use ($values) {
                static $callCount = 0;
                $callCount++;

                $variableMap = [
                    'query_cache_type' => ['Value' => $values['query_cache_type'] ?? 'OFF'],
                    'innodb_flush_log_at_trx_commit' => ['Value' => $values['innodb_flush_log_at_trx_commit'] ?? '1'],
                    'log_bin' => ['Value' => $values['log_bin'] ?? 'OFF'],
                    'innodb_buffer_pool_size' => ['Value' => $values['innodb_buffer_pool_size'] ?? '134217728'],
                ];

                // Return values in order of calls
                $keys = array_keys($variableMap);
                $index = ($callCount - 1) % count($keys);
                return $variableMap[$keys[$index]];
            });
    }

    private function createSuggestionFactory(): SuggestionFactory
    {
        $arrayLoader = new ArrayLoader(['default' => 'Suggestion: {{ message }}']);
        $twigEnvironment = new Environment($arrayLoader);
        return new SuggestionFactory(new TwigTemplateRenderer($twigEnvironment));
    }

    private function createDefaultResult(): Result
    {
        return $this->createMock(Result::class);
    }

    private function getDefaultValues(): array
    {
        return ['Value' => 'OFF'];
    }
}
