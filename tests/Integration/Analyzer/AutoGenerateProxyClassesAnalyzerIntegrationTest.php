<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\AutoGenerateProxyClassesAnalyzer;
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
    public function it_does_not_warn_in_development_environment(): void
    {
        $twigTemplateRenderer = $this->createTwigRenderer();
        $suggestionFactory = new SuggestionFactory($twigTemplateRenderer);

        // Test with development environment (should not warn)
        $autoGenerateProxyClassesAnalyzer = new AutoGenerateProxyClassesAnalyzer(
            $this->entityManager,
            $suggestionFactory,
            environment: 'dev',
        );

        $issueCollection = $autoGenerateProxyClassesAnalyzer->analyze(QueryDataCollection::empty());

        // Should NOT detect issues in development
        self::assertCount(0, $issueCollection);
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
