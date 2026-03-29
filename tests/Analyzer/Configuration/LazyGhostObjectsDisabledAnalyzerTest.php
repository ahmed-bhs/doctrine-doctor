<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Configuration;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\LazyGhostObjectsDisabledAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LazyGhostObjectsDisabledAnalyzerTest extends TestCase
{
    private string $tempDir;

    private LazyGhostObjectsDisabledAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/doctrine-doctor-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir . '/config/packages', 0777, true);

        $this->analyzer = new LazyGhostObjectsDisabledAnalyzer(
            new SuggestionFactory(new PhpTemplateRenderer()),
            $this->tempDir,
        );
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tempDir);
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        self::assertInstanceOf(AnalyzerInterface::class, $this->analyzer);
    }

    #[Test]
    public function it_detects_absent_lazy_ghost_objects_config(): void
    {
        $this->writeConfig('doctrine.yaml', <<<'YAML'
            doctrine:
                orm:
                    auto_generate_proxy_classes: false
            YAML);

        $issues = $this->analyzer->analyze(QueryDataCollection::empty());
        $issuesArray = $issues->toArray();

        self::assertNotEmpty($issuesArray, 'Should detect missing lazy ghost objects config');
    }

    #[Test]
    public function it_detects_explicitly_disabled_lazy_ghost_objects(): void
    {
        $this->writeConfig('doctrine.yaml', <<<'YAML'
            doctrine:
                orm:
                    enable_lazy_ghost_objects: false
            YAML);

        $issues = $this->analyzer->analyze(QueryDataCollection::empty());
        $issuesArray = $issues->toArray();

        self::assertNotEmpty($issuesArray, 'Should detect explicitly disabled lazy ghost objects');
    }

    #[Test]
    public function it_does_not_flag_when_enabled_in_global_config(): void
    {
        $this->writeConfig('doctrine.yaml', <<<'YAML'
            doctrine:
                orm:
                    enable_lazy_ghost_objects: true
            YAML);

        $issues = $this->analyzer->analyze(QueryDataCollection::empty());
        $issuesArray = $issues->toArray();

        self::assertEmpty($issuesArray, 'Should not flag when lazy ghost objects are enabled');
    }

    #[Test]
    public function it_does_not_flag_when_enabled_in_when_at_prod_block(): void
    {
        $this->writeConfig('doctrine.yaml', <<<'YAML'
            when@prod:
                doctrine:
                    orm:
                        enable_lazy_ghost_objects: true
            YAML);

        $issues = $this->analyzer->analyze(QueryDataCollection::empty());
        $issuesArray = $issues->toArray();

        self::assertEmpty($issuesArray, 'Should not flag when enabled in when@prod block');
    }

    #[Test]
    public function it_does_not_flag_when_enabled_in_prod_directory_config(): void
    {
        mkdir($this->tempDir . '/config/packages/prod', 0777, true);
        $this->writeConfig('prod/doctrine.yaml', <<<'YAML'
            doctrine:
                orm:
                    enable_lazy_ghost_objects: true
            YAML);

        $issues = $this->analyzer->analyze(QueryDataCollection::empty());
        $issuesArray = $issues->toArray();

        self::assertEmpty($issuesArray, 'Should not flag when enabled in prod directory config');
    }

    #[Test]
    public function it_does_not_flag_when_no_config_file_found(): void
    {
        $issues = $this->analyzer->analyze(QueryDataCollection::empty());
        $issuesArray = $issues->toArray();

        self::assertEmpty($issuesArray, 'Should not flag when no Doctrine config files found');
    }

    #[Test]
    public function it_returns_info_severity(): void
    {
        $this->writeConfig('doctrine.yaml', <<<'YAML'
            doctrine:
                orm:
                    enable_lazy_ghost_objects: false
            YAML);

        $issues = $this->analyzer->analyze(QueryDataCollection::empty());
        $issuesArray = $issues->toArray();

        self::assertNotEmpty($issuesArray);
        foreach ($issuesArray as $issue) {
            self::assertEquals(Severity::INFO, $issue->getSeverity());
        }
    }

    #[Test]
    public function it_provides_a_suggestion(): void
    {
        $this->writeConfig('doctrine.yaml', <<<'YAML'
            doctrine:
                orm:
                    enable_lazy_ghost_objects: false
            YAML);

        $issues = $this->analyzer->analyze(QueryDataCollection::empty());
        $issuesArray = $issues->toArray();

        self::assertNotEmpty($issuesArray);
        foreach ($issuesArray as $issue) {
            $suggestion = $issue->getSuggestion();
            self::assertNotNull($suggestion, 'Every issue should have a suggestion');

            $code = $suggestion->getCode();
            self::assertStringContainsString('lazy ghost objects', strtolower($code));
        }
    }

    #[Test]
    public function it_prioritizes_when_at_prod_over_global(): void
    {
        $this->writeConfig('doctrine.yaml', <<<'YAML'
            doctrine:
                orm:
                    enable_lazy_ghost_objects: false
            when@prod:
                doctrine:
                    orm:
                        enable_lazy_ghost_objects: true
            YAML);

        $issues = $this->analyzer->analyze(QueryDataCollection::empty());
        $issuesArray = $issues->toArray();

        self::assertEmpty($issuesArray, 'when@prod block should override global config');
    }

    private function writeConfig(string $filename, string $content): void
    {
        $path = $this->tempDir . '/config/packages/' . $filename;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $content);
    }

    private function rmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = scandir($path);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ('.' !== $file && '..' !== $file) {
                $filePath = $path . '/' . $file;
                if (is_dir($filePath)) {
                    $this->rmdir($filePath);
                } else {
                    unlink($filePath);
                }
            }
        }

        rmdir($path);
    }
}
