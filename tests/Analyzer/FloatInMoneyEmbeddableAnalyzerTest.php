<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\FloatInMoneyEmbeddableAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for FloatInMoneyEmbeddableAnalyzer.
 * Detects float usage in Money embeddables.
 */
final class FloatInMoneyEmbeddableAnalyzerTest extends TestCase
{
    private FloatInMoneyEmbeddableAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity',
            __DIR__ . '/../Fixtures/Embeddable',
        ]);

        $this->analyzer = new FloatInMoneyEmbeddableAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_float_in_money_embeddable(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: MoneyWithFloat should be detected
        $issuesArray = $issues->toArray();
        $moneyWithFloatIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getDescription(), 'MoneyWithFloat'));

        self::assertNotNull($moneyWithFloatIssue, 'Should detect float in MoneyWithFloat embeddable');
        self::assertEquals('integrity', $moneyWithFloatIssue->getCategory());
        self::assertEquals('critical', $moneyWithFloatIssue->getSeverity()->value);
    }

    #[Test]
    public function it_provides_suggestion_with_alternatives(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];
        $suggestion = $issue->getSuggestion();
        self::assertNotNull($suggestion);
    }

    #[Test]
    public function issue_contains_embeddable_information(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];
        $backtrace = $issue->getBacktrace();

        self::assertNotNull($backtrace);
        self::assertArrayHasKey('entity', $backtrace);
        self::assertArrayHasKey('field', $backtrace);
    }
}
