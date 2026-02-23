<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadeAllAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Template\Renderer\TwigTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Integration test for CascadeAllAnalyzer.
 *
 * Tests the analyzer's ability to detect issues with entity metadata.
 */
final class CascadeAllAnalyzerIntegrationTest extends TestCase
{
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        $configuration = PlatformAnalyzerTestHelper::createTestConfiguration([
            __DIR__ . '/../../Fixtures/Entity',
            __DIR__ . '/../../Fixtures/Entity/Bad',
        ]);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->entityManager = new EntityManager($connection, $configuration);
    }

    #[Test]
    public function it_analyzes_without_errors(): void
    {
        $cascadeAllAnalyzer = $this->createAnalyzer();
        $issues = $cascadeAllAnalyzer->analyze(QueryDataCollection::empty());

        self::assertInstanceOf(IssueCollection::class, $issues);
    }

    #[Test]
    public function it_handles_entities_gracefully(): void
    {
        $cascadeAllAnalyzer = $this->createAnalyzer();
        $issues = $cascadeAllAnalyzer->analyze(QueryDataCollection::empty());

        foreach ($issues as $issue) {
            self::assertNotNull($issue);
        }

        self::assertTrue(true); // No exceptions thrown
    }

    #[Test]
    public function it_analyzes_all_entities_without_errors(): void
    {
        $cascadeAllAnalyzer = $this->createAnalyzer();
        $issues = $cascadeAllAnalyzer->analyze(QueryDataCollection::empty());

        self::assertInstanceOf(IssueCollection::class, $issues);

        // Iterate through all issues to ensure they're valid
        $issueCount = 0;
        foreach ($issues as $issue) {
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
        $cascadeAllAnalyzer = $this->createAnalyzer();

        // Run analysis twice
        $issues1 = $cascadeAllAnalyzer->analyze(QueryDataCollection::empty());
        $issues2 = $cascadeAllAnalyzer->analyze(QueryDataCollection::empty());

        // Should return same number of issues
        self::assertCount(count($issues1), $issues2, 'Analyzer should return consistent results on repeated analysis');
    }

    #[Test]
    public function it_validates_issue_severity_is_appropriate(): void
    {
        $cascadeAllAnalyzer = $this->createAnalyzer();
        $issues = $cascadeAllAnalyzer->analyze(QueryDataCollection::empty());

        $validSeverities = ['critical', 'warning', 'info'];

        foreach ($issues as $issue) {
            $severityValue = $issue->getSeverity()->value;
            self::assertContains($severityValue, $validSeverities, "Issue severity must be one of: " . implode(', ', $validSeverities));
        }

        // Ensure we always have at least one assertion
        self::assertTrue(true, 'Severity validation completed');
    }

    #[Test]
    public function it_detects_cascade_all_on_many_to_one(): void
    {
        $cascadeAllAnalyzer = $this->createAnalyzer();
        $issues = $cascadeAllAnalyzer->analyze(QueryDataCollection::empty());

        // Find issues related to OrderWithCascadeAll
        $cascadeAllIssues = [];
        foreach ($issues as $issue) {
            $data = $issue->getData();
            if (str_contains($data['entity'] ?? '', 'OrderWithCascadeAll')) {
                $cascadeAllIssues[] = $issue;
            }
        }

        // Should detect at least 2 issues (user and items fields)
        self::assertGreaterThanOrEqual(2, count($cascadeAllIssues), 'Should detect cascade="all" on both user and items fields');

        // Verify issue on 'user' field (ManyToOne to independent entity)
        $userIssue = null;
        foreach ($cascadeAllIssues as $cascadeAllIssue) {
            $data = $cascadeAllIssue->getData();
            if ('user' === ($data['field'] ?? '')) {
                $userIssue = $cascadeAllIssue;
                break;
            }
        }

        self::assertInstanceOf(IntegrityIssue::class, $userIssue, 'Should detect cascade="all" on user field');
        self::assertSame('Dangerous cascade="all" Detected', $userIssue->getTitle());
        self::assertEquals('critical', $userIssue->getSeverity()->value, 'ManyToOne with cascade="all" to User should be critical');
    }

    private function createAnalyzer(): CascadeAllAnalyzer
    {
        $entityManager = $this->entityManager;
        $suggestionFactory = new SuggestionFactory($this->createTwigRenderer());

        // No logger provided - errors will be silent unless explicitly passed
        return new CascadeAllAnalyzer($entityManager, $suggestionFactory);
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
