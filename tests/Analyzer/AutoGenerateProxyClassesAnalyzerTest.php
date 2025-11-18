<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\AutoGenerateProxyClassesAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\TwigTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Test for AutoGenerateProxyClassesAnalyzer.
 *
 * This analyzer detects auto_generate_proxy_classes configuration issues
 * by reading YAML files.
 *
 * Note: Full integration tests exist in AutoGenerateProxyClassesAnalyzerIntegrationTest.
 * These unit tests verify basic analyzer behavior.
 */
final class AutoGenerateProxyClassesAnalyzerTest extends TestCase
{
    private AutoGenerateProxyClassesAnalyzer $analyzer;
    private string $tempDir;

    protected function setUp(): void
    {
        // Create temporary directory for test configs
        $this->tempDir = sys_get_temp_dir() . '/doctrine-doctor-test-' . uniqid();
        mkdir($this->tempDir);
        mkdir($this->tempDir . '/config');
        mkdir($this->tempDir . '/config/packages');

        $twigTemplateRenderer = $this->createTwigRenderer();
        $suggestionFactory = new SuggestionFactory($twigTemplateRenderer);

        $this->analyzer = new AutoGenerateProxyClassesAnalyzer(
            $suggestionFactory,
            $this->tempDir,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_returns_issue_collection(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertIsObject($issues);
        self::assertIsIterable($issues);
    }

    #[Test]
    public function it_handles_empty_query_collection(): void
    {
        // Arrange: Config analyzers don't use queries
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should analyze independently of queries
        self::assertIsObject($issues);
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        // Assert
        self::assertInstanceOf(\AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface::class, $this->analyzer);
    }

    #[Test]
    public function it_does_not_throw_on_analysis(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act & Assert: Should not throw exceptions
        $this->expectNotToPerformAssertions();
        $this->analyzer->analyze($queries);
    }

    #[Test]
    public function it_returns_iterable_issues(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Can iterate over issues
        $count = 0;
        foreach ($issues as $issue) {
            $count++;
            self::assertNotNull($issue);
        }

        self::assertGreaterThanOrEqual(0, $count);
    }

    #[Test]
    public function it_returns_empty_collection_when_no_config_exists(): void
    {
        // Arrange: No doctrine.yaml file
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should return empty collection
        self::assertCount(0, $issues->toArray());
    }

    #[Test]
    public function it_detects_issue_when_config_file_has_auto_generate_enabled(): void
    {
        // Arrange: Create config with auto_generate enabled
        $configContent = <<<YAML
doctrine:
    orm:
        auto_generate_proxy_classes: true
YAML;
        file_put_contents($this->tempDir . '/config/packages/doctrine.yaml', $configContent);

        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect the issue
        self::assertGreaterThan(0, count($issues->toArray()));
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
