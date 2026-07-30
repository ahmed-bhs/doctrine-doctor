<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Infrastructure\Strategy\PostgreSQL;

use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\PostgreSQL\PostgreSQLAnalysisStrategy;
use AhmedBhs\DoctrineDoctor\Template\Renderer\TwigTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Unit tests for PostgreSQLAnalysisStrategy::analyzePerformanceConfig().
 */
final class PostgreSQLAnalysisStrategyPerformanceTest extends TestCase
{
    #[Test]
    public function it_detects_shared_buffers_too_small(): void
    {
        $connection = $this->createMock(Connection::class);
        $detector = $this->createMock(DatabasePlatformDetector::class);

        $this->mockShowVariables($connection, $detector, [
            'shared_buffers' => '48MB', // < 64MB
            'work_mem' => '4MB',
            'synchronous_commit' => 'off',
        ]);

        $strategy = new PostgreSQLAnalysisStrategy(
            $connection,
            $this->createSuggestionFactory(),
            $detector,
        );

        /** @var \Traversable<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface> $result */
        $result = $strategy->analyzePerformanceConfig();
        $issues = iterator_to_array($result);

        $sharedBuffersIssues = array_filter(
            $issues,
            fn ($issue) =>
            str_contains((string) $issue->getTitle(), 'shared_buffers'),
        );

        self::assertCount(1, $sharedBuffersIssues, 'Should detect shared_buffers < 128MB');
        $issue = reset($sharedBuffersIssues);
        self::assertEquals('warning', $issue->getSeverity()->value); // < 64MB = warning
    }

    #[Test]
    public function it_detects_shared_buffers_very_small(): void
    {
        $connection = $this->createMock(Connection::class);
        $detector = $this->createMock(DatabasePlatformDetector::class);

        $this->mockShowVariables($connection, $detector, [
            'shared_buffers' => '96MB', // Between 64MB and 128MB
            'work_mem' => '4MB',
            'synchronous_commit' => 'off',
        ]);

        $strategy = new PostgreSQLAnalysisStrategy(
            $connection,
            $this->createSuggestionFactory(),
            $detector,
        );

        /** @var \Traversable<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface> $result */
        $result = $strategy->analyzePerformanceConfig();
        $issues = iterator_to_array($result);

        $sharedBuffersIssues = array_filter(
            $issues,
            fn ($issue) =>
            str_contains((string) $issue->getTitle(), 'shared_buffers'),
        );

        self::assertCount(1, $sharedBuffersIssues);
        $issue = reset($sharedBuffersIssues);
        self::assertEquals('info', $issue->getSeverity()->value); // >= 64MB = info
    }

    #[Test]
    public function it_detects_work_mem_too_small(): void
    {
        $connection = $this->createMock(Connection::class);
        $detector = $this->createMock(DatabasePlatformDetector::class);

        $this->mockShowVariables($connection, $detector, [
            'shared_buffers' => '256MB',
            'work_mem' => '2MB', // < 4MB
            'synchronous_commit' => 'off',
        ]);

        $strategy = new PostgreSQLAnalysisStrategy(
            $connection,
            $this->createSuggestionFactory(),
            $detector,
        );

        /** @var \Traversable<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface> $result */
        $result = $strategy->analyzePerformanceConfig();
        $issues = iterator_to_array($result);

        $workMemIssues = array_filter(
            $issues,
            fn ($issue) =>
            str_contains((string) $issue->getTitle(), 'work_mem'),
        );

        self::assertCount(1, $workMemIssues, 'Should detect work_mem < 4MB');
        $issue = reset($workMemIssues);
        self::assertEquals('info', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_synchronous_commit_on(): void
    {
        $connection = $this->createMock(Connection::class);
        $detector = $this->createMock(DatabasePlatformDetector::class);

        $this->mockShowVariables($connection, $detector, [
            'shared_buffers' => '256MB',
            'work_mem' => '4MB',
            'synchronous_commit' => 'on',
        ]);

        $strategy = new PostgreSQLAnalysisStrategy(
            $connection,
            $this->createSuggestionFactory(),
            $detector,
        );

        /** @var \Traversable<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface> $result */
        $result = $strategy->analyzePerformanceConfig();
        $issues = iterator_to_array($result);

        $syncCommitIssues = array_filter(
            $issues,
            fn ($issue) =>
            str_contains((string) $issue->getTitle(), 'synchronous commit'),
        );

        self::assertCount(1, $syncCommitIssues, 'Should detect synchronous_commit = on');
        $issue = reset($syncCommitIssues);
        self::assertEquals('info', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_ignores_synchronous_commit_off(): void
    {
        $connection = $this->createMock(Connection::class);
        $detector = $this->createMock(DatabasePlatformDetector::class);

        $this->mockShowVariables($connection, $detector, [
            'shared_buffers' => '256MB',
            'work_mem' => '4MB',
            'synchronous_commit' => 'off',
        ]);

        $strategy = new PostgreSQLAnalysisStrategy(
            $connection,
            $this->createSuggestionFactory(),
            $detector,
        );

        /** @var \Traversable<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface> $result */
        $result = $strategy->analyzePerformanceConfig();
        $issues = iterator_to_array($result);

        $syncCommitIssues = array_filter(
            $issues,
            fn ($issue) =>
            str_contains((string) $issue->getTitle(), 'synchronous commit'),
        );

        self::assertCount(0, $syncCommitIssues, 'Should not detect issue when synchronous_commit = off');
    }

    #[Test]
    public function it_returns_no_issues_when_all_configs_optimal(): void
    {
        $connection = $this->createMock(Connection::class);
        $detector = $this->createMock(DatabasePlatformDetector::class);

        $this->mockShowVariables($connection, $detector, [
            'shared_buffers' => '256MB',
            'work_mem' => '4MB',
            'synchronous_commit' => 'off',
        ]);

        $strategy = new PostgreSQLAnalysisStrategy(
            $connection,
            $this->createSuggestionFactory(),
            $detector,
        );

        /** @var \Traversable<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface> $result */
        $result = $strategy->analyzePerformanceConfig();
        $issues = iterator_to_array($result);

        self::assertCount(0, $issues, 'Should return no issues when all configs are optimal');
    }

    #[Test]
    public function it_parses_postgresql_size_formats(): void
    {
        $connection = $this->createMock(Connection::class);
        $detector = $this->createMock(DatabasePlatformDetector::class);

        // Test different size formats
        $this->mockShowVariables($connection, $detector, [
            'shared_buffers' => '8192kB', // = 8MB < 128MB
            'work_mem' => '4MB',
            'synchronous_commit' => 'off',
        ]);

        $strategy = new PostgreSQLAnalysisStrategy(
            $connection,
            $this->createSuggestionFactory(),
            $detector,
        );

        /** @var \Traversable<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface> $result */
        $result = $strategy->analyzePerformanceConfig();
        $issues = iterator_to_array($result);

        $sharedBuffersIssues = array_filter(
            $issues,
            fn ($issue) =>
            str_contains((string) $issue->getTitle(), 'shared_buffers'),
        );

        self::assertCount(1, $sharedBuffersIssues, 'Should correctly parse kB format');
    }

    #[Test]
    public function it_detects_multiple_issues(): void
    {
        $connection = $this->createMock(Connection::class);
        $detector = $this->createMock(DatabasePlatformDetector::class);

        // All settings are suboptimal
        $this->mockShowVariables($connection, $detector, [
            'shared_buffers' => '64MB',  // Too small
            'work_mem' => '2MB',         // Too small
            'synchronous_commit' => 'on', // Should be off in dev
        ]);

        $strategy = new PostgreSQLAnalysisStrategy(
            $connection,
            $this->createSuggestionFactory(),
            $detector,
        );

        /** @var \Traversable<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface> $result */
        $result = $strategy->analyzePerformanceConfig();
        $issues = iterator_to_array($result);

        // Should detect all 3 issues
        self::assertCount(3, $issues, 'Should detect all 3 performance issues');
    }

    private function mockShowVariables(Connection $connection, DatabasePlatformDetector $detector, array $values): void
    {
        /** @phpstan-ignore-next-line Mock object has expects() method */
        $connection->expects(self::any())
            ->method('executeQuery')
            ->willReturnCallback(fn () => $this->createMock(Result::class));

        /** @phpstan-ignore-next-line Mock object has expects() method */
        $detector->expects(self::any())
            ->method('fetchAssociative')
            ->willReturnCallback(function () use ($values) {
                static $callCount = 0;
                $callCount++;

                $variableMap = [
                    'shared_buffers' => ['shared_buffers' => $values['shared_buffers'] ?? '128MB'],
                    'work_mem' => ['work_mem' => $values['work_mem'] ?? '4MB'],
                    'synchronous_commit' => ['synchronous_commit' => $values['synchronous_commit'] ?? 'on'],
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
}
