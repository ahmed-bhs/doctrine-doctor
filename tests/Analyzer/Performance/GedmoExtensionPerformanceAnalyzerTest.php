<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\GedmoExtensionPerformanceAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GedmoExtensionPerformanceAnalyzer.
 * Detects entities using Gedmo Loggable or Translatable extensions.
 */
final class GedmoExtensionPerformanceAnalyzerTest extends TestCase
{
    private \Doctrine\ORM\EntityManager $entityManager;

    private GedmoExtensionPerformanceAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/GedmoTest',
        ]);

        $this->analyzer = new GedmoExtensionPerformanceAnalyzer(
            $this->entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        self::assertInstanceOf(AnalyzerInterface::class, $this->analyzer);
    }

    #[Test]
    public function it_detects_loggable_entity(): void
    {
        $issues = $this->analyzer->analyzeMetadata();
        $issuesArray = $issues->toArray();

        $loggableIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'Loggable'),
        );

        self::assertNotEmpty($loggableIssues, 'Should detect entity using Gedmo Loggable');
    }

    #[Test]
    public function it_detects_translatable_entity(): void
    {
        $issues = $this->analyzer->analyzeMetadata();
        $issuesArray = $issues->toArray();

        $translatableIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'Translatable'),
        );

        self::assertNotEmpty($translatableIssues, 'Should detect entity using Gedmo Translatable');
    }

    #[Test]
    public function it_does_not_flag_entity_without_gedmo(): void
    {
        $issues = $this->analyzer->analyzeMetadata();
        $issuesArray = $issues->toArray();

        $withoutGedmoIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'EntityWithoutGedmo'),
        );

        self::assertEmpty($withoutGedmoIssues, 'Should not flag entity without Gedmo extensions');
    }

    #[Test]
    public function it_returns_warning_for_loggable(): void
    {
        $issues = $this->analyzer->analyzeMetadata();
        $issuesArray = $issues->toArray();

        $loggableIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'Loggable'),
        );

        self::assertNotEmpty($loggableIssues);

        foreach ($loggableIssues as $issue) {
            self::assertEquals(Severity::WARNING, $issue->getSeverity(), 'Loggable should have WARNING severity');
        }
    }

    #[Test]
    public function it_returns_info_for_translatable(): void
    {
        $issues = $this->analyzer->analyzeMetadata();
        $issuesArray = $issues->toArray();

        $translatableIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'Translatable'),
        );

        self::assertNotEmpty($translatableIssues);

        foreach ($translatableIssues as $issue) {
            self::assertEquals(Severity::INFO, $issue->getSeverity(), 'Translatable should have INFO severity');
        }
    }

    #[Test]
    public function it_provides_suggestion_for_loggable(): void
    {
        $issues = $this->analyzer->analyzeMetadata();
        $issuesArray = $issues->toArray();

        $loggableIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'Loggable'),
        );

        self::assertNotEmpty($loggableIssues);

        foreach ($loggableIssues as $issue) {
            $suggestion = $issue->getSuggestion();
            self::assertNotNull($suggestion, 'Loggable issues should have suggestion');

            $code = $suggestion->getCode();
            self::assertTrue(
                str_contains($code, 'Loggable') || str_contains($code, 'changelog'),
                'Suggestion should mention Loggable impact',
            );
        }
    }

    #[Test]
    public function it_provides_suggestion_for_translatable(): void
    {
        $issues = $this->analyzer->analyzeMetadata();
        $issuesArray = $issues->toArray();

        $translatableIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'Translatable'),
        );

        self::assertNotEmpty($translatableIssues);

        foreach ($translatableIssues as $issue) {
            $suggestion = $issue->getSuggestion();
            self::assertNotNull($suggestion, 'Translatable issues should have suggestion');

            $code = $suggestion->getCode();
            self::assertTrue(
                str_contains($code, 'Translatable') || str_contains($code, 'locale'),
                'Suggestion should mention Translatable impact',
            );
        }
    }

    #[Test]
    public function it_handles_empty_metadata_gracefully(): void
    {
        $configuration = PlatformAnalyzerTestHelper::createTestConfiguration([__DIR__ . '/../../Fixtures/NonExistentPath']);

        $connection = \Doctrine\DBAL\DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $emptyEm = new \Doctrine\ORM\EntityManager($connection, $configuration);
        $analyzer = new GedmoExtensionPerformanceAnalyzer(
            $emptyEm,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );

        $issues = $analyzer->analyzeMetadata();

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_creates_performance_issues(): void
    {
        $issues = $this->analyzer->analyzeMetadata();
        $issuesArray = $issues->toArray();

        self::assertNotEmpty($issuesArray, 'Should create at least one issue');

        foreach ($issuesArray as $issue) {
            self::assertInstanceOf(PerformanceIssue::class, $issue);
        }
    }

    #[Test]
    public function it_uses_correct_issue_types(): void
    {
        $issues = $this->analyzer->analyzeMetadata();
        $issuesArray = $issues->toArray();

        $issueTypes = array_map(fn ($issue) => $issue->getType(), $issuesArray);

        self::assertTrue(
            in_array(IssueType::GEDMO_LOGGABLE->value, $issueTypes, true)
            || in_array(IssueType::GEDMO_TRANSLATABLE->value, $issueTypes, true),
            'Should use GEDMO_LOGGABLE or GEDMO_TRANSLATABLE issue types',
        );
    }

    #[Test]
    public function it_uses_generator_pattern(): void
    {
        $issues = $this->analyzer->analyzeMetadata();

        self::assertInstanceOf(\Generator::class, $issues->getIterator());
    }
}
