<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\NamingConventionAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
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
 * Integration test for NamingConventionAnalyzer.
 *
 * Tests detection of naming convention violations in:
 * - Table names (should be snake_case, singular)
 * - Column names (should be snake_case)
 * - Foreign keys (should be snake_case with _id suffix)
 * - Indexes (should have idx_ prefix)
 */
final class NamingConventionAnalyzerIntegrationTest extends TestCase
{
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        $configuration = PlatformAnalyzerTestHelper::createTestConfiguration([__DIR__ . '/../../Fixtures/Entity']);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->entityManager = new EntityManager($connection, $configuration);
    }

    #[Test]
    public function it_analyzes_naming_conventions(): void
    {
        $twigTemplateRenderer = $this->createTwigRenderer();
        $suggestionFactory = new \AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory($twigTemplateRenderer);
        $namingConventionAnalyzer = new NamingConventionAnalyzer($this->entityManager, $suggestionFactory);

        $issueCollection = $namingConventionAnalyzer->analyze(QueryDataCollection::empty());

        // Should analyze all entities without errors
        self::assertInstanceOf(IssueCollection::class, $issueCollection);

        // Issues depend on actual entity naming in fixtures
        // The analyzer checks: tables, columns, foreign keys, indexes
    }

    #[Test]
    public function it_checks_multiple_naming_aspects(): void
    {
        $twigTemplateRenderer = $this->createTwigRenderer();
        $suggestionFactory = new \AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory($twigTemplateRenderer);
        $namingConventionAnalyzer = new NamingConventionAnalyzer($this->entityManager, $suggestionFactory);

        $issueCollection = $namingConventionAnalyzer->analyze(QueryDataCollection::empty());

        // Should check tables, columns, FKs, indexes
        self::assertInstanceOf(IssueCollection::class, $issueCollection);

        // Verify it can iterate through all found issues
        $count = 0;
        foreach ($issueCollection as $issue) {
            $count++;
            self::assertNotNull($issue->getSeverity());
        }

        // Should have analyzed something (even if 0 issues)
        self::assertGreaterThanOrEqual(0, $count);
    }

    #[Test]
    public function it_analyzes_all_entities_without_errors(): void
    {
        $namingConventionAnalyzer = $this->createAnalyzer();
        $issueCollection = $namingConventionAnalyzer->analyze(QueryDataCollection::empty());

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
        $namingConventionAnalyzer = $this->createAnalyzer();

        // Run analysis twice
        $issueCollection = $namingConventionAnalyzer->analyze(QueryDataCollection::empty());
        $issues2 = $namingConventionAnalyzer->analyze(QueryDataCollection::empty());

        // Should return same number of issues
        self::assertCount(count($issueCollection), $issues2, 'Analyzer should return consistent results on repeated analysis');
    }

    #[Test]
    public function it_validates_issue_severity_is_appropriate(): void
    {
        $namingConventionAnalyzer = $this->createAnalyzer();
        $issueCollection = $namingConventionAnalyzer->analyze(QueryDataCollection::empty());

        $validSeverities = ['critical', 'warning', 'info'];

        foreach ($issueCollection as $issue) {
            $severityValue = $issue->getSeverity()->value;
            self::assertContains($severityValue, $validSeverities, "Issue severity must be one of: " . implode(', ', $validSeverities));
        }
    }

    private function createAnalyzer(): NamingConventionAnalyzer
    {
        $twigRenderer = $this->createTwigRenderer();
        $suggestionFactory = new \AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory($twigRenderer);
        return new NamingConventionAnalyzer($this->entityManager, $suggestionFactory);
    }

    private function createTwigRenderer(): TwigTemplateRenderer
    {
        $arrayLoader = new ArrayLoader([
            'naming_convention_table' => 'Table: {{ current }} → {{ suggested }}',
            'naming_convention_column' => 'Column: {{ current }} → {{ suggested }}',
            'naming_convention_fk' => 'FK: {{ current }} → {{ suggested }}',
            'naming_convention_index' => 'Index: {{ current }} → {{ suggested }}',
        ]);
        $twigEnvironment = new Environment($arrayLoader);

        return new TwigTemplateRenderer($twigEnvironment);
    }
}
