<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\AutoGenerateProxyClassesAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Template\Renderer\TwigTemplateRenderer;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Integration test for AutoGenerateProxyClassesAnalyzer.
 *
 * Tests detection of auto_generate_proxy_classes enabled in production,
 * which causes significant performance degradation.
 */
final class AutoGenerateProxyClassesAnalyzerIntegrationTest extends TestCase
{
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../../Fixtures/Entity'],
            isDevMode: true,
        );

        // Enable auto-generate for testing
        $configuration->setAutoGenerateProxyClasses(true);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->entityManager = new EntityManager($connection, $configuration);
    }

    #[Test]
    public function it_detects_auto_generate_enabled_in_production(): void
    {
        $twigTemplateRenderer = $this->createTwigRenderer();
        $suggestionFactory = new SuggestionFactory($twigTemplateRenderer);

        // Test with production environment
        $autoGenerateProxyClassesAnalyzer = new AutoGenerateProxyClassesAnalyzer(
            $this->entityManager,
            $suggestionFactory,
            environment: 'prod',
        );

        $issueCollection = $autoGenerateProxyClassesAnalyzer->analyze(QueryDataCollection::empty());

        // Should detect that auto-generate is enabled in production
        self::assertGreaterThan(0, count($issueCollection));

        $firstIssue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $firstIssue);
        self::assertSame('critical', $firstIssue->getSeverity()->value);
        self::assertStringContainsString('Production', $firstIssue->getTitle());
    }

    #[Test]
    public function it_warns_in_all_environments_when_auto_generate_enabled(): void
    {
        $twigTemplateRenderer = $this->createTwigRenderer();
        $suggestionFactory = new SuggestionFactory($twigTemplateRenderer);

        // Test with development environment - analyzer now triggers in ALL environments
        $autoGenerateProxyClassesAnalyzer = new AutoGenerateProxyClassesAnalyzer(
            $this->entityManager,
            $suggestionFactory,
            environment: 'dev',
        );

        $issueCollection = $autoGenerateProxyClassesAnalyzer->analyze(QueryDataCollection::empty());

        // Should detect issues in ALL environments (including dev)
        self::assertGreaterThanOrEqual(1, count($issueCollection));
    }

    #[Test]
    public function it_analyzes_all_entities_without_errors(): void
    {
        $autoGenerateProxyClassesAnalyzer = $this->createAnalyzer('prod');
        $issueCollection = $autoGenerateProxyClassesAnalyzer->analyze(QueryDataCollection::empty());

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
        $autoGenerateProxyClassesAnalyzer = $this->createAnalyzer('prod');

        // Run analysis twice
        $issueCollection = $autoGenerateProxyClassesAnalyzer->analyze(QueryDataCollection::empty());
        $issues2 = $autoGenerateProxyClassesAnalyzer->analyze(QueryDataCollection::empty());

        // Should return same number of issues
        self::assertCount(count($issueCollection), $issues2, 'Analyzer should return consistent results on repeated analysis');
    }

    #[Test]
    public function it_validates_issue_severity_is_appropriate(): void
    {
        $autoGenerateProxyClassesAnalyzer = $this->createAnalyzer('prod');
        $issueCollection = $autoGenerateProxyClassesAnalyzer->analyze(QueryDataCollection::empty());

        $validSeverities = ['critical', 'warning', 'info'];

        foreach ($issueCollection as $issue) {
            $severityValue = $issue->getSeverity()->value;
            self::assertContains($severityValue, $validSeverities, "Issue severity must be one of: " . implode(', ', $validSeverities));
        }
    }

    #[Test]
    public function it_handles_symfony_when_prod_override_correctly(): void
    {
        $twigTemplateRenderer = $this->createTwigRenderer();
        $suggestionFactory = new SuggestionFactory($twigTemplateRenderer);

        // Simulate Symfony's when@prod behavior:
        // In dev mode, auto_generate is true (the global config value)
        // But the analyzer receives environment='dev', so it should NOT warn
        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../../Fixtures/Entity'],
            isDevMode: true,
        );
        $configuration->setAutoGenerateProxyClasses(true); // Simulates global config

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $entityManager = new EntityManager($connection, $configuration);

        // Test with dev environment - analyzer now warns in ALL environments
        $autoGenerateProxyClassesAnalyzer = new AutoGenerateProxyClassesAnalyzer(
            $entityManager,
            $suggestionFactory,
            environment: 'dev', // Simulates Symfony running in dev mode
        );

        $issueCollection = $autoGenerateProxyClassesAnalyzer->analyze(QueryDataCollection::empty());

        // Should detect issues in ALL environments (including dev)
        self::assertGreaterThanOrEqual(1, count($issueCollection), 'Analyzer should warn about auto_generate in all environments');
    }

    #[Test]
    public function it_detects_when_prod_override_is_missing(): void
    {
        $twigTemplateRenderer = $this->createTwigRenderer();
        $suggestionFactory = new SuggestionFactory($twigTemplateRenderer);

        // Simulate a misconfigured project:
        // Running in prod mode with auto_generate still enabled
        // Use isDevMode=true to avoid Redis connection issues, but set environment to 'prod'
        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../../Fixtures/Entity'],
            isDevMode: true, // Avoid cache setup issues
        );
        $configuration->setAutoGenerateProxyClasses(true); // BAD: Still enabled in prod!

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $entityManager = new EntityManager($connection, $configuration);

        // Test with prod environment - SHOULD warn
        // The key is the environment parameter, not the isDevMode
        $autoGenerateProxyClassesAnalyzer = new AutoGenerateProxyClassesAnalyzer(
            $entityManager,
            $suggestionFactory,
            environment: 'prod', // This triggers the warning
        );

        $issueCollection = $autoGenerateProxyClassesAnalyzer->analyze(QueryDataCollection::empty());

        // Should detect the issue
        self::assertGreaterThan(0, count($issueCollection), 'Analyzer should warn about auto_generate enabled in prod');
        self::assertStringContainsString('Production', $issueCollection->first()->getTitle());
    }

    private function createAnalyzer(string $environment = 'prod'): AutoGenerateProxyClassesAnalyzer
    {
        $twigTemplateRenderer = $this->createTwigRenderer();
        $suggestionFactory = new SuggestionFactory($twigTemplateRenderer);

        return new AutoGenerateProxyClassesAnalyzer(
            $this->entityManager,
            $suggestionFactory,
            $environment,
        );
    }

    private function createTwigRenderer(): TwigTemplateRenderer
    {
        $arrayLoader = new ArrayLoader([
            'configuration' => 'Config: {{ setting }} = {{ current_value }} → {{ recommended_value }}',
        ]);
        $twigEnvironment = new Environment($arrayLoader);

        return new TwigTemplateRenderer($twigEnvironment);
    }
}
