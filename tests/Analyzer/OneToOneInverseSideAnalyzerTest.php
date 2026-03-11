<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\OneToOneInverseSideAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OneToOneInverseSideAnalyzerTest extends TestCase
{
    private OneToOneInverseSideAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/OneToOneInverseSideTest',
        ]);

        $this->analyzer = new OneToOneInverseSideAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );
    }

    #[Test]
    public function it_detects_inverse_side_of_one_to_one(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $issuesArray = $issues->toArray();
        $countryIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'Country');
        });

        self::assertCount(1, $countryIssues, 'Should detect Country as inverse side of OneToOne');

        $issue = reset($countryIssues);
        self::assertStringContainsString('capitalCity', $issue->getTitle());
    }

    #[Test]
    public function it_does_not_flag_owning_side(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $issuesArray = $issues->toArray();
        $capitalIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'CapitalCity');
        });

        self::assertCount(0, $capitalIssues, 'Owning side should not be flagged');
    }

    #[Test]
    public function it_does_not_flag_unidirectional_one_to_one(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $issuesArray = $issues->toArray();
        $profileIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'UserProfile')
                || str_contains($issue->getTitle(), 'UserAccount');
        });

        self::assertCount(0, $profileIssues, 'Unidirectional OneToOne should not be flagged');
    }

    #[Test]
    public function it_assigns_warning_severity(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $issuesArray = $issues->toArray();
        $countryIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'Country');
        });

        $issue = reset($countryIssues);
        self::assertNotFalse($issue);
        self::assertEquals('warning', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_provides_suggestion(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $issuesArray = $issues->toArray();
        $countryIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'Country');
        });

        $issue = reset($countryIssues);
        self::assertNotFalse($issue);
        self::assertNotNull($issue->getSuggestion());
    }

    #[Test]
    public function it_mentions_target_entity_in_description(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $issuesArray = $issues->toArray();
        $countryIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'Country');
        });

        $issue = reset($countryIssues);
        self::assertNotFalse($issue);
        self::assertStringContainsString('CapitalCity', $issue->getDescription());
        self::assertStringContainsString('N+1', $issue->getDescription());
    }

    #[Test]
    public function it_includes_entity_and_field_in_data(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        $issuesArray = $issues->toArray();
        $countryIssues = array_filter($issuesArray, static function ($issue) {
            return str_contains($issue->getTitle(), 'Country');
        });

        $issue = reset($countryIssues);
        self::assertNotFalse($issue);
        $data = $issue->getData();

        self::assertArrayHasKey('entity', $data);
        self::assertArrayHasKey('field', $data);
        self::assertArrayHasKey('target_entity', $data);
        self::assertEquals('capitalCity', $data['field']);
        self::assertStringContainsString('CapitalCity', $data['target_entity']);
    }

    #[Test]
    public function it_only_detects_one_issue_total(): void
    {
        $issues = $this->analyzer->analyze(QueryDataBuilder::create()->build());

        self::assertCount(1, $issues->toArray(), 'Should only detect 1 inverse side (Country::$capitalCity)');
    }

    #[Test]
    public function it_has_analyzer_name_and_description(): void
    {
        self::assertStringContainsString('OneToOne', $this->analyzer->getName());
        self::assertStringContainsString('OneToOne', $this->analyzer->getDescription());
    }
}
