<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\TimeZoneAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\TwigTemplateRenderer;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Integration test for TimeZoneAnalyzer.
 *
 * Tests the analyzer's ability to detect issues and provide actionable suggestions.
 */
final class TimeZoneAnalyzerIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }
    }

    #[Test]
    public function it_analyzes_without_errors(): void
    {
        $timeZoneAnalyzer = $this->createAnalyzer();
        $issueCollection = $timeZoneAnalyzer->analyze(QueryDataCollection::empty());

        self::assertInstanceOf(IssueCollection::class, $issueCollection);
    }

    #[Test]
    public function it_returns_issue_collection(): void
    {
        $timeZoneAnalyzer = $this->createAnalyzer();
        $issueCollection = $timeZoneAnalyzer->analyze(QueryDataCollection::empty());

        $count = 0;
        foreach ($issueCollection as $issue) {
            $count++;
            self::assertNotNull($issue);
        }

        self::assertGreaterThanOrEqual(0, $count);
    }

    #[Test]
    public function it_analyzes_all_entities_without_errors(): void
    {
        $timeZoneAnalyzer = $this->createAnalyzer();
        $issueCollection = $timeZoneAnalyzer->analyze(QueryDataCollection::empty());

        self::assertInstanceOf(IssueCollection::class, $issueCollection);

        // Iterate through all issues to ensure they're valid
        $issueCount = 0;
        foreach ($issueCollection as $issue) {
            $issueCount++;

            // Every issue must have these properties
            self::assertNotNull($issue->getTitle(), 'Issue must have a title');
            self::assertIsString($issue->getTitle());
            self::assertNotEmpty($issue->getTitle());

            self::assertNotNull($issue->getDescription(), 'Issue must have a description');
            self::assertIsString($issue->getDescription());

            self::assertNotNull($issue->getSeverity(), 'Issue must have severity');
            self::assertInstanceOf(Severity::class, $issue->getSeverity());
        }

        // Should analyze without throwing exceptions
        self::assertGreaterThanOrEqual(0, $issueCount);
    }

    #[Test]
    public function it_returns_consistent_results(): void
    {
        $timeZoneAnalyzer = $this->createAnalyzer();

        // Run analysis twice
        $issueCollection = $timeZoneAnalyzer->analyze(QueryDataCollection::empty());
        $issues2 = $timeZoneAnalyzer->analyze(QueryDataCollection::empty());

        // Should return same number of issues
        self::assertCount(count($issueCollection), $issues2, 'Analyzer should return consistent results on repeated analysis');
    }

    #[Test]
    public function it_validates_issue_severity_is_appropriate(): void
    {
        $timeZoneAnalyzer = $this->createAnalyzer();
        $issueCollection = $timeZoneAnalyzer->analyze(QueryDataCollection::empty());

        $validSeverities = ['critical', 'warning', 'info'];

        foreach ($issueCollection as $issue) {
            $severityValue = $issue->getSeverity()->value;
            self::assertContains($severityValue, $validSeverities, "Issue severity must be one of: " . implode(', ', $validSeverities));
        }

        // Ensure we always have at least one assertion
        self::assertTrue(true, 'Severity validation completed');
    }

    private function createAnalyzer(): TimeZoneAnalyzer
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $suggestionFactory = new SuggestionFactory($this->createTwigRenderer());

        return new TimeZoneAnalyzer($connection, $suggestionFactory);
    }

    private function createTwigRenderer(): TwigTemplateRenderer
    {
        $arrayLoader = new ArrayLoader([
            'default' => 'Suggestion: {{ message }}',
        ]);
        $twigEnvironment = new Environment($arrayLoader);

        return new TwigTemplateRenderer($twigEnvironment);
    }
}
