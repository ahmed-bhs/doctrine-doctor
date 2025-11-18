<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\AutoGenerateProxyClassesAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Template\Renderer\TwigTemplateRenderer;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Integration test for AutoGenerateProxyClassesAnalyzer.
 *
 * Tests detection of auto_generate_proxy_classes enabled in production
 * by reading YAML configuration files.
 */
final class AutoGenerateProxyClassesAnalyzerIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Create temporary directory for test configs
        $this->tempDir = sys_get_temp_dir() . '/doctrine-doctor-test-' . uniqid();
        mkdir($this->tempDir);
        mkdir($this->tempDir . '/config');
        mkdir($this->tempDir . '/config/packages');
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_detects_auto_generate_enabled_in_when_prod_block(): void
    {
        // Create config file with when@prod block having auto_generate enabled
        $configContent = <<<YAML
doctrine:
    orm:
        auto_generate_proxy_classes: false  # Global (dev)

when@prod:
    doctrine:
        orm:
            auto_generate_proxy_classes: true  # BAD: Enabled in prod!
YAML;

        file_put_contents($this->tempDir . '/config/packages/doctrine.yaml', $configContent);

        $analyzer = $this->createAnalyzer($this->tempDir);
        $issueCollection = $analyzer->analyze(QueryDataCollection::empty());

        // Should detect that auto-generate is enabled in production
        self::assertGreaterThan(0, count($issueCollection));

        $firstIssue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $firstIssue);
        self::assertSame('critical', $firstIssue->getSeverity()->value);
        self::assertStringContainsString('Production', $firstIssue->getTitle());
    }

    #[Test]
    public function it_does_not_warn_when_auto_generate_disabled_in_when_prod(): void
    {
        // Create config file with when@prod block having auto_generate disabled
        $configContent = <<<YAML
doctrine:
    orm:
        auto_generate_proxy_classes: true  # Global (dev) - OK

when@prod:
    doctrine:
        orm:
            auto_generate_proxy_classes: false  # GOOD: Disabled in prod!
YAML;

        file_put_contents($this->tempDir . '/config/packages/doctrine.yaml', $configContent);

        $analyzer = $this->createAnalyzer($this->tempDir);
        $issueCollection = $analyzer->analyze(QueryDataCollection::empty());

        // Should NOT detect issues - config is correct
        self::assertCount(0, $issueCollection);
    }

    #[Test]
    public function it_detects_missing_when_prod_override_with_global_true(): void
    {
        // Create config file with NO when@prod override - global true applies to prod
        $configContent = <<<YAML
doctrine:
    orm:
        auto_generate_proxy_classes: true  # BAD: No prod override, so true in prod!

when@prod:
    doctrine:
        orm:
            proxy_dir: '%kernel.build_dir%/doctrine/orm/Proxies'
            # Missing: auto_generate_proxy_classes override
YAML;

        file_put_contents($this->tempDir . '/config/packages/doctrine.yaml', $configContent);

        $analyzer = $this->createAnalyzer($this->tempDir);
        $issueCollection = $analyzer->analyze(QueryDataCollection::empty());

        // Should detect the issue - global config will be used in prod
        self::assertGreaterThan(0, count($issueCollection));
        $firstIssue = $issueCollection->first();
        self::assertNotNull($firstIssue);
        self::assertStringContainsString('Production', $firstIssue->getTitle());
    }

    #[Test]
    public function it_detects_auto_generate_enabled_in_prod_directory(): void
    {
        // Create dedicated prod config file
        mkdir($this->tempDir . '/config/packages/prod');

        $configContent = <<<YAML
doctrine:
    orm:
        auto_generate_proxy_classes: true  # BAD: Enabled in prod!
YAML;

        file_put_contents($this->tempDir . '/config/packages/prod/doctrine.yaml', $configContent);

        $analyzer = $this->createAnalyzer($this->tempDir);
        $issueCollection = $analyzer->analyze(QueryDataCollection::empty());

        // Should detect the issue
        self::assertGreaterThan(0, count($issueCollection));
        $firstIssue = $issueCollection->first();
        self::assertInstanceOf(IssueInterface::class, $firstIssue);
        self::assertSame('critical', $firstIssue->getSeverity()->value);
    }

    #[Test]
    public function it_does_not_warn_when_auto_generate_disabled_in_prod_directory(): void
    {
        // Create dedicated prod config file with auto_generate disabled
        mkdir($this->tempDir . '/config/packages/prod');

        $configContent = <<<YAML
doctrine:
    orm:
        auto_generate_proxy_classes: false  # GOOD!
YAML;

        file_put_contents($this->tempDir . '/config/packages/prod/doctrine.yaml', $configContent);

        $analyzer = $this->createAnalyzer($this->tempDir);
        $issueCollection = $analyzer->analyze(QueryDataCollection::empty());

        // Should NOT detect issues
        self::assertCount(0, $issueCollection);
    }

    #[Test]
    public function it_handles_numeric_values(): void
    {
        // Test with numeric value (0 = disabled, 1 = enabled)
        $configContent = <<<YAML
when@prod:
    doctrine:
        orm:
            auto_generate_proxy_classes: 1  # BAD: 1 = enabled
YAML;

        file_put_contents($this->tempDir . '/config/packages/doctrine.yaml', $configContent);

        $analyzer = $this->createAnalyzer($this->tempDir);
        $issueCollection = $analyzer->analyze(QueryDataCollection::empty());

        self::assertGreaterThan(0, count($issueCollection));
    }

    #[Test]
    public function it_handles_numeric_zero_as_disabled(): void
    {
        // Test with numeric 0 (disabled)
        $configContent = <<<YAML
when@prod:
    doctrine:
        orm:
            auto_generate_proxy_classes: 0  # GOOD: 0 = disabled
YAML;

        file_put_contents($this->tempDir . '/config/packages/doctrine.yaml', $configContent);

        $analyzer = $this->createAnalyzer($this->tempDir);
        $issueCollection = $analyzer->analyze(QueryDataCollection::empty());

        self::assertCount(0, $issueCollection);
    }

    #[Test]
    public function it_skips_analysis_when_no_config_found(): void
    {
        // No doctrine.yaml file at all
        $analyzer = $this->createAnalyzer($this->tempDir);
        $issueCollection = $analyzer->analyze(QueryDataCollection::empty());

        // Should skip analysis and return empty collection
        self::assertCount(0, $issueCollection);
    }

    #[Test]
    public function it_validates_issue_severity_is_critical(): void
    {
        $configContent = <<<YAML
when@prod:
    doctrine:
        orm:
            auto_generate_proxy_classes: true
YAML;

        file_put_contents($this->tempDir . '/config/packages/doctrine.yaml', $configContent);

        $analyzer = $this->createAnalyzer($this->tempDir);
        $issueCollection = $analyzer->analyze(QueryDataCollection::empty());

        foreach ($issueCollection as $issue) {
            self::assertSame('critical', $issue->getSeverity()->value);
        }
    }

    #[Test]
    public function it_returns_consistent_results(): void
    {
        $configContent = <<<YAML
when@prod:
    doctrine:
        orm:
            auto_generate_proxy_classes: true
YAML;

        file_put_contents($this->tempDir . '/config/packages/doctrine.yaml', $configContent);

        $analyzer = $this->createAnalyzer($this->tempDir);

        // Run analysis twice
        $issues1 = $analyzer->analyze(QueryDataCollection::empty());
        $issues2 = $analyzer->analyze(QueryDataCollection::empty());

        // Should return same number of issues
        self::assertCount(count($issues1), $issues2);
    }

    #[Test]
    public function it_provides_detailed_issue_information(): void
    {
        $configContent = <<<YAML
when@prod:
    doctrine:
        orm:
            auto_generate_proxy_classes: true
YAML;

        file_put_contents($this->tempDir . '/config/packages/doctrine.yaml', $configContent);

        $analyzer = $this->createAnalyzer($this->tempDir);
        $issueCollection = $analyzer->analyze(QueryDataCollection::empty());

        $firstIssue = $issueCollection->first();
        self::assertNotNull($firstIssue);

        // Verify issue has all required information
        self::assertNotEmpty($firstIssue->getTitle());
        self::assertNotEmpty($firstIssue->getDescription());
        self::assertNotNull($firstIssue->getSuggestion());
        self::assertInstanceOf(Severity::class, $firstIssue->getSeverity());

        // Verify description mentions performance impact
        self::assertStringContainsString('filesystem', strtolower($firstIssue->getDescription()));
    }

    private function createAnalyzer(string $projectDir): AutoGenerateProxyClassesAnalyzer
    {
        $twigTemplateRenderer = $this->createTwigRenderer();
        $suggestionFactory = new SuggestionFactory($twigTemplateRenderer);

        return new AutoGenerateProxyClassesAnalyzer(
            $suggestionFactory,
            $projectDir,
        );
    }

    private function createTwigRenderer(): TwigTemplateRenderer
    {
        $arrayLoader = new ArrayLoader([
            'configuration' => 'Config: {{ setting }} = {{ current_value }} → {{ recommended_value }}',
        ]);
        $twigEnvironment = new Environment($arrayLoader);

        return new TwigTemplateRenderer($twigEnvironment);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
