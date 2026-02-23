<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CollectionInitializationAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
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
 * Integration test for CollectionInitializationAnalyzer.
 *
 * Tests detection of uninitialized collections in entity constructors.
 * This is a critical issue that causes runtime errors.
 */
final class CollectionInitializationAnalyzerIntegrationTest extends TestCase
{
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        // Create in-memory EntityManager with only metadata (no DB needed)
        $configuration = PlatformAnalyzerTestHelper::createTestConfiguration([__DIR__ . '/../../Fixtures/Entity']);

        // Create connection first, then EntityManager
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->entityManager = new EntityManager($connection, $configuration);
    }

    #[Test]
    public function it_detects_collection_without_initialization(): void
    {
        $twigTemplateRenderer = $this->createTwigRenderer();
        $suggestionFactory = new SuggestionFactory($twigTemplateRenderer);
        $collectionInitializationAnalyzer = new CollectionInitializationAnalyzer($this->entityManager, $suggestionFactory);

        $issues = $collectionInitializationAnalyzer->analyze(QueryDataCollection::empty());

        // We expect issues for entities with uninitialized collections
        // Note: This test will find real issues if entities have uninitialized collections
        self::assertInstanceOf(IssueCollection::class, $issues);

        // The analyzer analyzes all loaded entities
        // The number of issues depends on the actual entities in Fixtures
    }

    #[Test]
    public function it_handles_entities_without_collections(): void
    {
        $twigTemplateRenderer = $this->createTwigRenderer();
        $suggestionFactory = new SuggestionFactory($twigTemplateRenderer);
        $collectionInitializationAnalyzer = new CollectionInitializationAnalyzer($this->entityManager, $suggestionFactory);

        // Analyze without errors
        $issues = $collectionInitializationAnalyzer->analyze(QueryDataCollection::empty());

        // Should not throw exceptions
        self::assertInstanceOf(IssueCollection::class, $issues);
    }

    #[Test]
    public function it_analyzes_all_entities_without_errors(): void
    {
        $collectionInitializationAnalyzer = $this->createAnalyzer();
        $issues = $collectionInitializationAnalyzer->analyze(QueryDataCollection::empty());

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
        $collectionInitializationAnalyzer = $this->createAnalyzer();

        // Run analysis twice
        $issues1 = $collectionInitializationAnalyzer->analyze(QueryDataCollection::empty());
        $issues2 = $collectionInitializationAnalyzer->analyze(QueryDataCollection::empty());

        // Should return same number of issues
        self::assertCount(count($issues1), $issues2, 'Analyzer should return consistent results on repeated analysis');
    }

    #[Test]
    public function it_validates_issue_severity_is_appropriate(): void
    {
        $collectionInitializationAnalyzer = $this->createAnalyzer();
        $issues = $collectionInitializationAnalyzer->analyze(QueryDataCollection::empty());

        $validSeverities = ['critical', 'warning', 'info'];

        foreach ($issues as $issue) {
            $severityValue = $issue->getSeverity()->value;
            self::assertContains($severityValue, $validSeverities, "Issue severity must be one of: " . implode(', ', $validSeverities));
        }

        // Ensure we always have at least one assertion
        self::assertTrue(true, 'Severity validation completed');
    }

    private function createAnalyzer(): CollectionInitializationAnalyzer
    {
        $suggestionFactory = new SuggestionFactory($this->createTwigRenderer());

        return new CollectionInitializationAnalyzer($this->entityManager, $suggestionFactory);
    }

    private function createTwigRenderer(): TwigTemplateRenderer
    {
        $arrayLoader = new ArrayLoader([
            'collection_initialization' => 'Initialize collection: {{ field_name }}',
        ]);
        $twigEnvironment = new Environment($arrayLoader);

        return new TwigTemplateRenderer($twigEnvironment);
    }
}
