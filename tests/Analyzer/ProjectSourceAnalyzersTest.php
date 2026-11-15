<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\ProjectSourceScanner;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\EntityManagerInServiceAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\UnusedInverseSideAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\CollectionFilteredInPhpAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Analyzers that read the application code (src/) next to the mapping.
 */
final class ProjectSourceAnalyzersTest extends TestCase
{
    private const string PROJECT = __DIR__ . '/../Fixtures/Project';

    #[Test]
    public function it_flags_a_service_that_receives_the_entity_manager(): void
    {
        $issues = $this->issues(new EntityManagerInServiceAnalyzer(new ProjectSourceScanner(self::PROJECT, ['App']), PlatformAnalyzerTestHelper::createSuggestionFactory(), PlatformAnalyzerTestHelper::createIssueFactory()));

        self::assertSame(['EntityManager Injected in Service: ShippingService'], $this->titles($issues));
        self::assertStringContainsString('only uses it to reach OrderRepository', strip_tags($issues[0]->getDescription()));
        self::assertSame('info', $issues[0]->getSeverity()->value);
    }

    #[Test]
    public function it_flags_only_the_inverse_side_that_nothing_reads(): void
    {
        $issues = $this->issues(new UnusedInverseSideAnalyzer($this->entityManager(), new ProjectSourceScanner(self::PROJECT, ['App']), PlatformAnalyzerTestHelper::createSuggestionFactory(), PlatformAnalyzerTestHelper::createIssueFactory()));

        self::assertSame(['Unused Inverse Side: Customer::$orders'], $this->titles($issues));
        self::assertStringContainsString('inverse side of Order::$customer', strip_tags($issues[0]->getDescription()));
    }

    #[Test]
    public function it_flags_a_collection_filtered_in_php_with_its_line(): void
    {
        $issues = $this->issues(new CollectionFilteredInPhpAnalyzer($this->entityManager(), new ProjectSourceScanner(self::PROJECT, ['App']), PlatformAnalyzerTestHelper::createSuggestionFactory(), PlatformAnalyzerTestHelper::createIssueFactory()));

        self::assertSame(['Collection Filtered in PHP: ShippingService::pendingLines()'], $this->titles($issues));
        self::assertStringContainsString('Order::$lines', strip_tags($issues[0]->getDescription()));
        self::assertSame(30, $issues[0]->getData()['line']);
    }

    #[Test]
    public function it_reports_nothing_without_a_source_directory(): void
    {
        $analyzer = new EntityManagerInServiceAnalyzer(new ProjectSourceScanner(self::PROJECT, ['missing']), PlatformAnalyzerTestHelper::createSuggestionFactory(), PlatformAnalyzerTestHelper::createIssueFactory());

        self::assertSame([], $this->issues($analyzer));
    }

    #[Test]
    public function it_takes_its_settings_from_the_configuration(): void
    {
        $scanner = new ProjectSourceScanner(self::PROJECT, ['App']);
        $services = new EntityManagerInServiceAnalyzer($scanner, PlatformAnalyzerTestHelper::createSuggestionFactory(), PlatformAnalyzerTestHelper::createIssueFactory(), ['Service']);
        $filters = new CollectionFilteredInPhpAnalyzer($this->entityManager(), $scanner, PlatformAnalyzerTestHelper::createSuggestionFactory(), PlatformAnalyzerTestHelper::createIssueFactory(), ['exists']);

        self::assertSame(['EntityManager Injected in Service: OrderRepository'], $this->titles($this->issues($services)));
        self::assertSame([], $this->issues($filters));
    }

    #[Test]
    public function it_does_not_say_only_when_the_service_also_builds_queries(): void
    {
        $analyzer = new EntityManagerInServiceAnalyzer(new ProjectSourceScanner(self::PROJECT, ['App']), PlatformAnalyzerTestHelper::createSuggestionFactory(), PlatformAnalyzerTestHelper::createIssueFactory(), ['Service']);

        $description = strip_tags($this->issues($analyzer)[0]->getDescription());

        self::assertStringContainsString('calls createQueryBuilder() on it', $description);
        self::assertStringNotContainsString('only uses it', $description);
    }

    #[Test]
    public function it_finds_raw_sql_concatenation_in_application_services(): void
    {
        $analyzer = new \AhmedBhs\DoctrineDoctor\Analyzer\Security\SQLInjectionInRawQueriesAnalyzer(
            $this->entityManager(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            null,
            null,
            new \AhmedBhs\DoctrineDoctor\Analyzer\Helper\DQLPatternMatcher(),
            new ProjectSourceScanner(self::PROJECT, ['App']),
        );

        $texts = array_map(static fn (IssueInterface $issue): string => $issue->getTitle() . ' ' . strip_tags($issue->getDescription()), array_values($analyzer->analyzeSourceCode()->toArray()));

        self::assertNotSame([], array_filter($texts, static fn (string $text): bool => str_contains($text, 'AuditSearch')), implode("\n", $texts));

        $issue = array_values(array_filter($analyzer->analyzeSourceCode()->toArray(), static fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'AuditSearch')))[0];
        $location = new \AhmedBhs\DoctrineDoctor\Issue\FindingLocator(\dirname(__DIR__, 2))->locate($issue);
        self::assertSame('tests/Fixtures/Project/App/Service/AuditSearch.php', $location->file);
        self::assertSame(24, $location->line);
    }

    #[Test]
    public function it_flags_a_collection_counted_by_loading_it(): void
    {
        $issues = $this->issues(new \AhmedBhs\DoctrineDoctor\Analyzer\Performance\CollectionCountedInPhpAnalyzer($this->entityManager(), new ProjectSourceScanner(self::PROJECT, ['App']), PlatformAnalyzerTestHelper::createSuggestionFactory(), PlatformAnalyzerTestHelper::createIssueFactory()));

        self::assertSame(['Collection Counted in PHP: CustomerReport::addressesPerCustomer()', 'Collection Counted in PHP: ShippingService::addressCount()'], $this->titles($issues));
        self::assertStringContainsString('Customer::$addresses', strip_tags($issues[0]->getDescription()));
    }

    #[Test]
    public function it_flags_a_sum_computed_in_php_over_loaded_entities(): void
    {
        $issues = $this->issues(new \AhmedBhs\DoctrineDoctor\Analyzer\Performance\AggregateInPhpAnalyzer(new ProjectSourceScanner(self::PROJECT, ['App']), PlatformAnalyzerTestHelper::createSuggestionFactory(), PlatformAnalyzerTestHelper::createIssueFactory()));

        self::assertSame(['Aggregate Computed in PHP: CustomerReport::totalSpent()'], $this->titles($issues));
        self::assertStringContainsString('getSpent()', strip_tags($issues[0]->getDescription()));
        self::assertSame(42, $issues[0]->getData()['line']);
    }

    private function entityManager(): \Doctrine\ORM\EntityManagerInterface
    {
        return PlatformAnalyzerTestHelper::createTestEntityManager([self::PROJECT . '/App/Entity']);
    }

    /**
     * @return list<IssueInterface>
     */
    private function issues(MetadataAnalyzerInterface $analyzer): array
    {
        return array_values($analyzer->analyzeMetadata()->toArray());
    }

    /**
     * @param list<IssueInterface> $issues
     * @return list<string>
     */
    private function titles(array $issues): array
    {
        return array_map(static fn (IssueInterface $issue): string => $issue->getTitle(), $issues);
    }
}
