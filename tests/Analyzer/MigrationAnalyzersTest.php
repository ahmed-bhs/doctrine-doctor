<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\MigrationStateAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\RiskyMigrationAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MigrationAnalyzersTest extends TestCase
{
    private const string MIGRATIONS = __DIR__ . '/../Fixtures/Migrations';

    #[Test]
    public function it_flags_a_migration_that_drops_data_without_a_way_back(): void
    {
        $titles = $this->titles(new RiskyMigrationAnalyzer(PlatformAnalyzerTestHelper::createSuggestionFactory(), $this->factory('Risky', $this->entityManager())));

        self::assertContains('Destructive Migration: Version20260101000001', $titles);
        self::assertContains('Migration Without down(): Version20260101000001', $titles);
        self::assertContains('Migration Uses the ORM: Version20260101000002', $titles);
        self::assertSame([], array_values(array_filter($titles, static fn (string $t): bool => str_contains($t, 'Version20260101000003'))));
    }

    #[Test]
    public function it_names_the_dropped_column_but_not_the_dropped_foreign_key(): void
    {
        $issues = array_values(array_filter(
            new RiskyMigrationAnalyzer(PlatformAnalyzerTestHelper::createSuggestionFactory(), $this->factory('Risky', $this->entityManager()))->analyzeMetadata()->toArray(),
            static fn (IssueInterface $issue): bool => str_starts_with($issue->getTitle(), 'Destructive'),
        ));

        self::assertStringContainsString('drops column added_at.', $issues[0]->getDescription());
    }

    #[Test]
    public function it_reports_migrations_that_were_not_executed(): void
    {
        $em = $this->entityManager();
        $issues = new MigrationStateAnalyzer($em, PlatformAnalyzerTestHelper::createSuggestionFactory(), $this->factory('Pending', $em))->analyzeMetadata()->toArray();

        self::assertCount(1, $issues);
        self::assertSame('Migrations Not Executed', $issues[0]->getTitle());
        self::assertStringContainsString('Version20260101000010', $issues[0]->getDescription());
    }

    #[Test]
    public function it_reports_a_mapping_that_no_migration_covers(): void
    {
        $em = $this->entityManager();
        $issues = new MigrationStateAnalyzer($em, PlatformAnalyzerTestHelper::createSuggestionFactory(), $this->factory(null, $em))->analyzeMetadata()->toArray();

        self::assertCount(1, $issues);
        self::assertSame('Mapping Changed Without a Migration', $issues[0]->getTitle());
        self::assertStringContainsString('CREATE TABLE', $issues[0]->getDescription());
    }

    #[Test]
    public function it_names_every_statement_that_drops_data(): void
    {
        $em = $this->entityManager();
        new \Doctrine\ORM\Tools\SchemaTool($em)->createSchema($em->getMetadataFactory()->getAllMetadata());
        foreach (['legacy_a', 'legacy_b', 'legacy_c'] as $table) {
            $em->getConnection()->executeStatement(sprintf('CREATE TABLE %s (id INTEGER)', $table));
        }

        $issues = new MigrationStateAnalyzer($em, PlatformAnalyzerTestHelper::createSuggestionFactory(), $this->factory(null, $em))->analyzeMetadata()->toArray();

        self::assertStringContainsString('3 of which drop', $issues[0]->getDescription());
        foreach (['legacy_a', 'legacy_b', 'legacy_c'] as $table) {
            self::assertStringContainsString('table ' . $table, $issues[0]->getDescription());
        }
    }

    #[Test]
    public function it_does_nothing_without_doctrine_migrations(): void
    {
        self::assertSame([], new RiskyMigrationAnalyzer(PlatformAnalyzerTestHelper::createSuggestionFactory())->analyzeMetadata()->toArray());
        self::assertSame([], new MigrationStateAnalyzer($this->entityManager(), PlatformAnalyzerTestHelper::createSuggestionFactory())->analyzeMetadata()->toArray());
    }

    private function entityManager(): EntityManagerInterface
    {
        return PlatformAnalyzerTestHelper::createTestEntityManager([__DIR__ . '/../Fixtures/Project/App/Entity']);
    }

    private function factory(?string $directory, EntityManagerInterface $em): DependencyFactory
    {
        $paths = null === $directory ? [] : ['AhmedBhs\\DoctrineDoctor\\Tests\\Fixtures\\Migrations\\' . $directory => self::MIGRATIONS . '/' . $directory];

        return DependencyFactory::fromEntityManager(new ConfigurationArray(['migrations_paths' => $paths]), new ExistingEntityManager($em));
    }

    /**
     * @return list<string>
     */
    private function titles(RiskyMigrationAnalyzer $analyzer): array
    {
        return array_map(static fn (IssueInterface $issue): string => $issue->getTitle(), array_values($analyzer->analyzeMetadata()->toArray()));
    }
}
