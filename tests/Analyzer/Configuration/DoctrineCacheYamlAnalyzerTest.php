<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Configuration;

use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\DoctrineCacheAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

final class DoctrineCacheYamlAnalyzerTest extends DatabaseTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/doctrine-doctor-yaml-test-' . uniqid();
        mkdir($this->tempDir . '/config/packages', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function it_detects_missing_metadata_cache_when_prod_section_exists(): void
    {
        $this->writeDoctrineYaml(<<<'YAML'
            when@prod:
                doctrine:
                    orm:
                        query_cache_driver:
                            type: pool
                            pool: doctrine.system_cache_pool
                        result_cache_driver:
                            type: pool
                            pool: doctrine.result_cache_pool
            YAML);

        $issues = $this->createAnalyzer()->analyze(QueryDataCollection::empty());
        $issuesArray = $issues->toArray();

        $metadataIssues = array_filter(
            $issuesArray,
            fn ($i) => str_contains((string) $i->getTitle(), 'Missing') && str_contains((string) $i->getTitle(), 'Metadata'),
        );

        self::assertNotEmpty($metadataIssues, 'Should detect missing metadata_cache_driver in when@prod');

        $issue = reset($metadataIssues);
        self::assertSame('critical', $issue->getSeverity()->value);
        self::assertStringContainsString('metadata_cache_driver', strtolower($issue->getDescription()));
    }

    #[Test]
    public function it_detects_missing_query_cache_when_prod_section_exists(): void
    {
        $this->writeDoctrineYaml(<<<'YAML'
            when@prod:
                doctrine:
                    orm:
                        metadata_cache_driver:
                            type: pool
                            pool: doctrine.system_cache_pool
                        result_cache_driver:
                            type: pool
                            pool: doctrine.result_cache_pool
            YAML);

        $issues = $this->createAnalyzer()->analyze(QueryDataCollection::empty());
        $issuesArray = $issues->toArray();

        $queryIssues = array_filter(
            $issuesArray,
            fn ($i) => str_contains((string) $i->getTitle(), 'Missing') && str_contains((string) $i->getTitle(), 'Query'),
        );

        self::assertNotEmpty($queryIssues, 'Should detect missing query_cache_driver in when@prod');

        $issue = reset($queryIssues);
        self::assertSame('critical', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_missing_result_cache_when_prod_section_exists(): void
    {
        $this->writeDoctrineYaml(<<<'YAML'
            when@prod:
                doctrine:
                    orm:
                        metadata_cache_driver:
                            type: pool
                            pool: doctrine.system_cache_pool
                        query_cache_driver:
                            type: pool
                            pool: doctrine.system_cache_pool
            YAML);

        $issues = $this->createAnalyzer()->analyze(QueryDataCollection::empty());
        $issuesArray = $issues->toArray();

        $resultIssues = array_filter(
            $issuesArray,
            fn ($i) => str_contains((string) $i->getTitle(), 'Missing') && str_contains((string) $i->getTitle(), 'Result'),
        );

        self::assertNotEmpty($resultIssues, 'Should detect missing result_cache_driver in when@prod');

        $issue = reset($resultIssues);
        self::assertSame('warning', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_all_three_missing_caches(): void
    {
        $this->writeDoctrineYaml(<<<'YAML'
            when@prod:
                doctrine:
                    orm:
                        auto_mapping: true
            YAML);

        $issues = $this->createAnalyzer()->analyze(QueryDataCollection::empty());
        $issuesArray = $issues->toArray();

        $missingIssues = array_filter(
            $issuesArray,
            fn ($i) => str_contains((string) $i->getTitle(), 'Missing'),
        );

        self::assertCount(3, $missingIssues, 'Should detect all three missing cache drivers');
    }

    #[Test]
    public function it_does_not_flag_when_all_caches_are_configured(): void
    {
        $this->writeDoctrineYaml(<<<'YAML'
            when@prod:
                doctrine:
                    orm:
                        metadata_cache_driver:
                            type: pool
                            pool: doctrine.system_cache_pool
                        query_cache_driver:
                            type: pool
                            pool: doctrine.system_cache_pool
                        result_cache_driver:
                            type: pool
                            pool: doctrine.result_cache_pool
            YAML);

        $issues = $this->createAnalyzer()->analyze(QueryDataCollection::empty());
        $issuesArray = $issues->toArray();

        $missingIssues = array_filter(
            $issuesArray,
            fn ($i) => str_contains((string) $i->getTitle(), 'Missing'),
        );

        self::assertEmpty($missingIssues, 'Should not flag when all cache drivers are configured');
    }

    #[Test]
    public function it_does_not_flag_when_prod_section_is_absent(): void
    {
        $this->writeDoctrineYaml(<<<'YAML'
            doctrine:
                orm:
                    auto_mapping: true
            YAML);

        $issues = $this->createAnalyzer()->analyze(QueryDataCollection::empty());
        $issuesArray = $issues->toArray();

        self::assertEmpty($issuesArray, 'Should not flag when when@prod section is entirely absent (may use split config files)');
    }

    #[Test]
    public function it_provides_suggestion_with_missing_cache_template(): void
    {
        $this->writeDoctrineYaml(<<<'YAML'
            when@prod:
                doctrine:
                    orm:
                        query_cache_driver:
                            type: pool
                            pool: doctrine.system_cache_pool
                        result_cache_driver:
                            type: pool
                            pool: doctrine.result_cache_pool
            YAML);

        $issues = $this->createAnalyzer()->analyze(QueryDataCollection::empty());
        $issuesArray = $issues->toArray();

        $metadataIssues = array_filter(
            $issuesArray,
            fn ($i) => str_contains((string) $i->getTitle(), 'Missing') && str_contains((string) $i->getTitle(), 'Metadata'),
        );

        $issue = reset($metadataIssues);
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
        $code = $suggestion->getCode();
        self::assertStringContainsString('metadata_cache_driver', $code);
        self::assertStringContainsString('doctrine.system_cache_pool', $code);
        self::assertStringContainsString('apcu', strtolower($code));
    }

    private function createAnalyzer(): DoctrineCacheAnalyzer
    {
        $suggestionFactory = new SuggestionFactory(new PhpTemplateRenderer());

        return new DoctrineCacheAnalyzer(
            $this->entityManager,
            $suggestionFactory,
            'dev',
            $this->tempDir,
        );
    }

    private function writeDoctrineYaml(string $content): void
    {
        file_put_contents($this->tempDir . '/config/packages/doctrine.yaml', $content);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }

        rmdir($path);
    }
}
