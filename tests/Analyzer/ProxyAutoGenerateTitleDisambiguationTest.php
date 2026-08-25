<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\AutoGenerateProxyClassesAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\DoctrineCacheAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AutoGenerateProxyClassesAnalyzer reads the production YAML configuration, while
 * DoctrineCacheAnalyzer reads the value the EntityManager actually holds at runtime.
 * Both report proxy auto-generation, and in production both can fire for the same
 * project, so their titles must stay distinguishable in the profiler panel.
 */
final class ProxyAutoGenerateTitleDisambiguationTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/doctrine-doctor-proxy-' . uniqid();
        mkdir($this->projectDir . '/config/packages', 0o777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->projectDir . '/config/packages/doctrine.yaml');
        @rmdir($this->projectDir . '/config/packages');
        @rmdir($this->projectDir . '/config');
        @rmdir($this->projectDir);
    }

    #[Test]
    public function it_reports_both_sources_under_distinct_titles(): void
    {
        $this->writeProductionConfig(true);

        $titles = array_merge(
            $this->proxyTitlesFromConfigFileAnalyzer(),
            $this->proxyTitlesFromRuntimeAnalyzer(autoGenerate: 1, environment: 'prod'),
        );

        self::assertCount(2, $titles);
        self::assertCount(2, array_unique($titles), 'Both analyzers reported the same title');
    }

    #[Test]
    public function it_marks_the_config_file_source_in_its_title(): void
    {
        $this->writeProductionConfig(true);

        foreach ($this->proxyTitlesFromConfigFileAnalyzer() as $title) {
            self::assertStringContainsString('config file', $title);
        }
    }

    #[Test]
    public function it_marks_the_runtime_source_in_its_title(): void
    {
        $this->writeProductionConfig(false);

        $titles = $this->proxyTitlesFromRuntimeAnalyzer(autoGenerate: 1, environment: 'prod');

        self::assertNotEmpty($titles);

        foreach ($titles as $title) {
            self::assertStringContainsString('runtime', $title);
        }
    }

    #[Test]
    public function it_detects_a_runtime_override_the_config_file_does_not_show(): void
    {
        $this->writeProductionConfig(false);

        self::assertEmpty(
            $this->proxyTitlesFromConfigFileAnalyzer(),
            'Production config declares false, so the config file analyzer must stay silent',
        );
        self::assertNotEmpty(
            $this->proxyTitlesFromRuntimeAnalyzer(autoGenerate: 1, environment: 'prod'),
            'The runtime analyzer is the only source that can see this override',
        );
    }

    #[Test]
    public function it_detects_a_production_config_the_runtime_does_not_show(): void
    {
        $this->writeProductionConfig(true);

        self::assertNotEmpty(
            $this->proxyTitlesFromConfigFileAnalyzer(),
            'The config file analyzer is the only source that can see this before deploying',
        );
        self::assertEmpty(
            $this->proxyTitlesFromRuntimeAnalyzer(autoGenerate: 0, environment: 'prod'),
            'The running EntityManager has auto-generation disabled, so the runtime analyzer stays silent',
        );
    }

    #[Test]
    public function it_keeps_the_runtime_analyzer_silent_outside_production(): void
    {
        $this->writeProductionConfig(false);

        self::assertEmpty($this->proxyTitlesFromRuntimeAnalyzer(autoGenerate: 1, environment: 'dev'));
    }

    private function writeProductionConfig(bool $autoGenerate): void
    {
        file_put_contents(
            $this->projectDir . '/config/packages/doctrine.yaml',
            sprintf(
                "doctrine:\n    orm:\n        auto_generate_proxy_classes: true\n"
                . "when@prod:\n    doctrine:\n        orm:\n            auto_generate_proxy_classes: %s\n",
                $autoGenerate ? 'true' : 'false',
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function proxyTitlesFromConfigFileAnalyzer(): array
    {
        $analyzer = new AutoGenerateProxyClassesAnalyzer(
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            $this->projectDir,
        );

        return $this->proxyTitles($analyzer->analyze(QueryDataCollection::empty())->toArray());
    }

    /**
     * @return list<string>
     */
    private function proxyTitlesFromRuntimeAnalyzer(int $autoGenerate, string $environment): array
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager();
        $entityManager->getConfiguration()->setAutoGenerateProxyClasses($autoGenerate);

        $analyzer = new DoctrineCacheAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            $environment,
            $this->projectDir,
        );

        return $this->proxyTitles($analyzer->analyze(QueryDataCollection::empty())->toArray());
    }

    /**
     * @param array<IssueInterface> $issues
     * @return list<string>
     */
    private function proxyTitles(array $issues): array
    {
        $titles = [];

        foreach ($issues as $issue) {
            if (str_contains(strtolower($issue->getTitle()), 'proxy auto-generation')) {
                $titles[] = $issue->getTitle();
            }
        }

        return $titles;
    }
}
