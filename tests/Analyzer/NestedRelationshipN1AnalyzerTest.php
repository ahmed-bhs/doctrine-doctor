<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\NestedRelationshipN1Analyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NestedRelationshipN1AnalyzerTest extends TestCase
{
    private NestedRelationshipN1Analyzer $analyzer;

    protected function setUp(): void
    {
        $issueFactory = new IssueFactory();
        $suggestionFactory = new SuggestionFactory(new PhpTemplateRenderer());

        $this->analyzer = new NestedRelationshipN1Analyzer($issueFactory, $suggestionFactory);
    }

    #[Test]
    public function it_detects_two_level_nested_n_plus_one(): void
    {
        // Simulates: $articles->getAuthor()->getCountry()
        // 1 query for articles, N queries for authors, N queries for countries
        $collection = QueryDataBuilder::create()
            // Load articles
            ->addQuery('SELECT * FROM articles', 0.010)
            // N+1 for authors (10 articles)
            ->addQuery('SELECT * FROM users WHERE id = 1', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 2', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 3', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 4', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 5', 0.005)
            // N+1 for countries (5 unique authors)
            ->addQuery('SELECT * FROM countries WHERE id = 10', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 11', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 12', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 13', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 14', 0.003)
            ->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues));

        $issue = $issues->toArray()[0];
        self::assertSame('nested_n_plus_one', $issue->getType());
        self::assertStringContainsString('Nested', $issue->getTitle());
    }

    #[Test]
    public function it_detects_three_level_nested_n_plus_one(): void
    {
        // Simulates: $articles->getAuthor()->getCountry()->getContinent()
        // 3 levels of nesting
        $collection = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM articles', 0.010)
            // Level 1: authors
            ->addQuery('SELECT * FROM users WHERE id = 1', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 2', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 3', 0.005)
            // Level 2: countries
            ->addQuery('SELECT * FROM countries WHERE id = 10', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 11', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 12', 0.003)
            // Level 3: continents
            ->addQuery('SELECT * FROM continents WHERE id = 100', 0.002)
            ->addQuery('SELECT * FROM continents WHERE id = 101', 0.002)
            ->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues));

        $issue = $issues->toArray()[0];
        self::assertStringContainsString('3', $issue->getDescription()); // Should mention 3 levels
    }

    #[Test]
    public function it_does_not_detect_flat_n_plus_one(): void
    {
        // Just a regular N+1 (not nested) - should not be detected by this analyzer
        $collection = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM articles', 0.010)
            ->addQuery('SELECT * FROM users WHERE id = 1', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 2', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 3', 0.005)
            ->build();

        $issues = $this->analyzer->analyze($collection);

        // Should not detect - only 2 tables (articles, users) - no nesting
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_assigns_higher_severity_for_deeper_nesting(): void
    {
        // Deep nesting (3 levels) should have higher severity
        $collection = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM articles', 0.010)
            // Level 1
            ->addQuery('SELECT * FROM users WHERE id = 1', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 2', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 3', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 4', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 5', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 6', 0.005)
            // Level 2
            ->addQuery('SELECT * FROM countries WHERE id = 10', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 11', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 12', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 13', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 14', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 15', 0.003)
            // Level 3
            ->addQuery('SELECT * FROM continents WHERE id = 100', 0.002)
            ->addQuery('SELECT * FROM continents WHERE id = 101', 0.002)
            ->addQuery('SELECT * FROM continents WHERE id = 102', 0.002)
            ->addQuery('SELECT * FROM continents WHERE id = 103', 0.002)
            ->addQuery('SELECT * FROM continents WHERE id = 104', 0.002)
            ->addQuery('SELECT * FROM continents WHERE id = 105', 0.002)
            ->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues));

        $issue = $issues->toArray()[0];
        // Deep nesting with many queries should be higher than info severity
        self::assertTrue(
            $issue->getSeverity()->isHigherThan(\AhmedBhs\DoctrineDoctor\ValueObject\Severity::info()),
            'Deep nested N+1 should have warning or critical severity',
        );
    }

    #[Test]
    public function it_creates_suggestion_for_nested_eager_loading(): void
    {
        $collection = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM articles', 0.010)
            ->addQuery('SELECT * FROM users WHERE id = 1', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 2', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 3', 0.005)
            ->addQuery('SELECT * FROM countries WHERE id = 10', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 11', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 12', 0.003)
            ->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues));

        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
        self::assertStringContainsString('JOIN FETCH', strtoupper($suggestion->getCode()));
    }

    #[Test]
    public function it_ignores_non_select_queries(): void
    {
        $collection = QueryDataBuilder::create()
            ->addQuery('UPDATE articles SET title = ?', 0.010)
            ->addQuery('INSERT INTO users VALUES (?)', 0.005)
            ->addQuery('DELETE FROM countries WHERE id = ?', 0.003)
            ->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_does_not_detect_unrelated_repeated_lookups_without_root_query(): void
    {
        // Two repeated lookup groups without an initial root query should not be
        // considered nested N+1.
        $collection = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 2', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 3', 0.005)
            ->addQuery('SELECT * FROM countries WHERE id = 10', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 11', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 12', 0.003)
            ->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_handles_empty_query_collection(): void
    {
        $collection = QueryDataBuilder::create()->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_identifies_relationship_chain_tables(): void
    {
        // Tests that the analyzer correctly identifies the chain: articles -> users -> countries
        $collection = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM articles', 0.010)
            // Need at least 3 queries per table to meet threshold
            ->addQuery('SELECT * FROM users WHERE id = 1', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 2', 0.005)
            ->addQuery('SELECT * FROM users WHERE id = 3', 0.005)
            ->addQuery('SELECT * FROM countries WHERE id = 10', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 11', 0.003)
            ->addQuery('SELECT * FROM countries WHERE id = 12', 0.003)
            ->build();

        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues));

        $description = $issues->toArray()[0]->getDescription();

        // Should mention at least 2 of the 3 tables in the chain
        $lowerDescription = strtolower($description);
        $matches = 0;
        if (str_contains($lowerDescription, 'users')) {
            ++$matches;
        }
        if (str_contains($lowerDescription, 'countries')) {
            ++$matches;
        }

        self::assertGreaterThanOrEqual(2, $matches, 'Should mention at least 2 tables from the chain');
    }
}
