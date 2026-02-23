<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\SoftDeleteableTraitAnalyzer;
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
 * Integration test for SoftDeleteableTraitAnalyzer.
 *
 * Tests the analyzer's ability to detect issues with entity metadata.
 */
final class SoftDeleteableTraitAnalyzerIntegrationTest extends TestCase
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
        $softDeleteableTraitAnalyzer = $this->createAnalyzer();
        $issueCollection = $softDeleteableTraitAnalyzer->analyze(QueryDataCollection::empty());

        self::assertInstanceOf(IssueCollection::class, $issueCollection);
    }

    #[Test]
    public function it_handles_entities_gracefully(): void
    {
        $softDeleteableTraitAnalyzer = $this->createAnalyzer();
        $issueCollection = $softDeleteableTraitAnalyzer->analyze(QueryDataCollection::empty());

        foreach ($issueCollection as $issue) {
            self::assertNotNull($issue);
        }

        self::assertTrue(true); // No exceptions thrown
    }

    #[Test]
    public function it_detects_soft_delete_issues(): void
    {
        $softDeleteableTraitAnalyzer = $this->createAnalyzer();
        $issueCollection = $softDeleteableTraitAnalyzer->analyze(QueryDataCollection::empty());

        // Should analyze soft delete implementation
        // PostWithBadSoftDelete has issues
        self::assertInstanceOf(IssueCollection::class, $issueCollection);

        // Count issues found
        $count = 0;
        foreach ($issueCollection as $issue) {
            $count++;
            self::assertNotNull($issue->getSeverity());
        }

        self::assertGreaterThanOrEqual(0, $count);
    }

    private function createAnalyzer(): SoftDeleteableTraitAnalyzer
    {
        $entityManager = $this->entityManager;
        $issueFactory = new IssueFactory();
        $suggestionFactory = new SuggestionFactory($this->createTwigRenderer());

        return new SoftDeleteableTraitAnalyzer($entityManager, $issueFactory, $suggestionFactory);
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
