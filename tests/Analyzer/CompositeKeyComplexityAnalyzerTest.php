<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CompositeKeyComplexityAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompositeKeyComplexityAnalyzerTest extends TestCase
{
    private CompositeKeyComplexityAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/CompositeKeyTest',
        ]);

        $this->analyzer = new CompositeKeyComplexityAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );
    }

    #[Test]
    public function it_detects_two_column_composite_key(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $issuesArray = $issues->toArray();
        $orderItemIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'OrderItemWithCompositeKey');
        });

        self::assertCount(1, $orderItemIssues, 'Should detect 2-column composite key');

        $issue = reset($orderItemIssues);
        self::assertStringContainsString('2 columns', $issue->getTitle());
    }

    #[Test]
    public function it_detects_three_column_composite_key(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $issuesArray = $issues->toArray();
        $matrixIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'PriceMatrixWithHeavyCompositeKey');
        });

        self::assertCount(1, $matrixIssues, 'Should detect 3-column composite key');

        $issue = reset($matrixIssues);
        self::assertStringContainsString('3 columns', $issue->getTitle());
    }

    #[Test]
    public function it_does_not_flag_surrogate_key_entities(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $issuesArray = $issues->toArray();
        $surrogateIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'OrderWithSurrogateKey')
                || str_contains($issue->getTitle(), 'ProductWithSurrogateKey');
        });

        self::assertCount(0, $surrogateIssues, 'Surrogate key entities should not be flagged');
    }

    #[Test]
    public function it_assigns_warning_severity_for_two_columns(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $issuesArray = $issues->toArray();
        $orderItemIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'OrderItemWithCompositeKey');
        });

        $issue = reset($orderItemIssues);
        self::assertNotFalse($issue);

        $severity = $issue->getSeverity()->value;
        self::assertContains($severity, ['warning', 'critical']);
    }

    #[Test]
    public function it_assigns_critical_severity_for_three_or_more_columns(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $issuesArray = $issues->toArray();
        $matrixIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'PriceMatrixWithHeavyCompositeKey');
        });

        $issue = reset($matrixIssues);
        self::assertNotFalse($issue);
        self::assertEquals('critical', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_escalates_to_critical_when_referenced_by_other_entities(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $issuesArray = $issues->toArray();
        $orderItemIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'OrderItemWithCompositeKey');
        });

        $issue = reset($orderItemIssues);
        self::assertNotFalse($issue);
        self::assertEquals('critical', $issue->getSeverity()->value, 'Should escalate to critical when referenced by PriceOverrideReferencingComposite');
        self::assertStringContainsString('PriceOverrideReferencingComposite', $issue->getDescription());
    }

    #[Test]
    public function it_provides_suggestions_for_all_issues(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $issuesArray = $issues->toArray();

        self::assertGreaterThanOrEqual(2, count($issuesArray), 'Should detect at least 2 composite keys');

        foreach ($issuesArray as $issue) {
            self::assertNotNull($issue->getSuggestion(), 'Each issue should have a suggestion');
        }
    }

    #[Test]
    public function it_includes_field_names_in_description(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $issuesArray = $issues->toArray();
        $matrixIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'PriceMatrixWithHeavyCompositeKey');
        });

        $issue = reset($matrixIssues);
        self::assertNotFalse($issue);
        self::assertStringContainsString('product', $issue->getDescription());
        self::assertStringContainsString('region', $issue->getDescription());
        self::assertStringContainsString('currency', $issue->getDescription());
    }

    #[Test]
    public function it_has_analyzer_name_and_description(): void
    {
        self::assertStringContainsString('Composite', $this->analyzer->getName());
        self::assertStringContainsString('composite', strtolower($this->analyzer->getDescription()));
    }
}
