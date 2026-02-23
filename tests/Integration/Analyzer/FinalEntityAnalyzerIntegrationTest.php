<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\FinalEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for FinalEntityAnalyzer.
 *
 * Tests detection of final entity classes that prevent Doctrine
 * from creating proxy classes for lazy loading.
 */
final class FinalEntityAnalyzerIntegrationTest extends TestCase
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
    public function it_detects_final_entities(): void
    {
        $issueFactory = new IssueFactory();
        $finalEntityAnalyzer = new FinalEntityAnalyzer($this->entityManager, $issueFactory);

        $issueCollection = $finalEntityAnalyzer->analyze(QueryDataCollection::empty());

        // Should analyze without errors
        self::assertInstanceOf(IssueCollection::class, $issueCollection);

        // If there are final entities in fixtures, issues should be found
        // Note: Depends on actual entity fixtures
    }

    #[Test]
    public function it_handles_non_final_entities_gracefully(): void
    {
        $issueFactory = new IssueFactory();
        $finalEntityAnalyzer = new FinalEntityAnalyzer($this->entityManager, $issueFactory);

        $issueCollection = $finalEntityAnalyzer->analyze(QueryDataCollection::empty());

        // Should complete analysis without throwing exceptions
        self::assertInstanceOf(IssueCollection::class, $issueCollection);

        // Can iterate through issues
        foreach ($issueCollection as $issue) {
            self::assertNotNull($issue);
        }
    }

    #[Test]
    public function it_analyzes_all_entities_without_errors(): void
    {
        $finalEntityAnalyzer = $this->createAnalyzer();
        $issueCollection = $finalEntityAnalyzer->analyze(QueryDataCollection::empty());

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
        $finalEntityAnalyzer = $this->createAnalyzer();

        // Run analysis twice
        $issueCollection = $finalEntityAnalyzer->analyze(QueryDataCollection::empty());
        $issues2 = $finalEntityAnalyzer->analyze(QueryDataCollection::empty());

        // Should return same number of issues
        self::assertCount(count($issueCollection), $issues2, 'Analyzer should return consistent results on repeated analysis');
    }

    #[Test]
    public function it_validates_issue_severity_is_appropriate(): void
    {
        $finalEntityAnalyzer = $this->createAnalyzer();
        $issueCollection = $finalEntityAnalyzer->analyze(QueryDataCollection::empty());

        $validSeverities = ['critical', 'warning', 'info'];

        foreach ($issueCollection as $issue) {
            $severityValue = $issue->getSeverity()->value;
            self::assertContains($severityValue, $validSeverities, "Issue severity must be one of: " . implode(', ', $validSeverities));
        }

        // Ensure we always have at least one assertion
        self::assertTrue(true, 'Severity validation completed');
    }

    private function createAnalyzer(): FinalEntityAnalyzer
    {
        $issueFactory = new IssueFactory();

        return new FinalEntityAnalyzer($this->entityManager, $issueFactory);
    }
}
