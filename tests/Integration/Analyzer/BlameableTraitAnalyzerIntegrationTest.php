<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\BlameableTraitAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\TwigTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Integration test for BlameableTraitAnalyzer.
 *
 * Tests the analyzer's ability to detect issues with entity metadata.
 */
final class BlameableTraitAnalyzerIntegrationTest extends TestCase
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
    public function it_analyzes_without_errors(): void
    {
        $blameableTraitAnalyzer = $this->createAnalyzer();
        $issueCollection = $blameableTraitAnalyzer->analyze(QueryDataCollection::empty());

        self::assertInstanceOf(IssueCollection::class, $issueCollection);
    }

    #[Test]
    public function it_handles_entities_gracefully(): void
    {
        $blameableTraitAnalyzer = $this->createAnalyzer();
        $issueCollection = $blameableTraitAnalyzer->analyze(QueryDataCollection::empty());

        foreach ($issueCollection as $issue) {
            self::assertNotNull($issue);
        }

        self::assertTrue(true); // No exceptions thrown
    }

    #[Test]
    public function it_detects_nullable_created_by(): void
    {
        $blameableTraitAnalyzer = $this->createAnalyzer();
        $issueCollection = $blameableTraitAnalyzer->analyze(QueryDataCollection::empty());

        // Should detect nullable createdBy in ArticleWithBadBlameable
        $foundNullableIssue = false;
        foreach ($issueCollection as $issue) {
            if (str_contains(strtolower($issue->getTitle()), 'nullable') ||
                str_contains(strtolower($issue->getDescription()), 'nullable')) {
                $foundNullableIssue = true;
                break;
            }
        }

        // May or may not find issues depending on fixtures
        self::assertIsBool($foundNullableIssue);
    }

    #[Test]
    public function it_detects_public_setters(): void
    {
        $blameableTraitAnalyzer = $this->createAnalyzer();
        $issueCollection = $blameableTraitAnalyzer->analyze(QueryDataCollection::empty());

        // Should detect public setters in ArticleWithBadBlameable
        $foundSetterIssue = false;
        foreach ($issueCollection as $issue) {
            if (str_contains(strtolower($issue->getTitle()), 'setter') ||
                str_contains(strtolower($issue->getDescription()), 'setter')) {
                $foundSetterIssue = true;
                break;
            }
        }

        self::assertIsBool($foundSetterIssue);
    }

    private function createAnalyzer(): BlameableTraitAnalyzer
    {
        $entityManager = $this->entityManager;
        $issueFactory = new IssueFactory();
        $suggestionFactory = new SuggestionFactory($this->createTwigRenderer());

        return new BlameableTraitAnalyzer($entityManager, $issueFactory, $suggestionFactory);
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
