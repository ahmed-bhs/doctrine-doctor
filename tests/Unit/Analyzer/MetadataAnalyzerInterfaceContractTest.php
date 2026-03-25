<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MetadataAnalyzerInterfaceContractTest extends TestCase
{
    #[Test]
    #[DataProvider('metadataAnalyzerClassesProvider')]
    public function it_extends_analyzer_interface(string $className): void
    {
        $implements = class_implements($className);
        self::assertIsArray($implements);

        self::assertArrayHasKey(
            AnalyzerInterface::class,
            $implements,
            sprintf('%s implements MetadataAnalyzerInterface which must extend AnalyzerInterface', $className),
        );
    }

    #[Test]
    #[DataProvider('metadataAnalyzerClassesProvider')]
    public function it_has_analyze_metadata_method(string $className): void
    {
        $reflection = new \ReflectionClass($className);

        self::assertTrue(
            $reflection->hasMethod('analyzeMetadata'),
            sprintf('%s must have an analyzeMetadata() method', $className),
        );
    }

    #[Test]
    #[DataProvider('metadataAnalyzerClassesProvider')]
    public function it_has_analyze_method_from_trait(string $className): void
    {
        $reflection = new \ReflectionClass($className);

        self::assertTrue(
            $reflection->hasMethod('analyze'),
            sprintf('%s must have an analyze() method (provided by MetadataAnalyzerTrait)', $className),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function metadataAnalyzerClassesProvider(): iterable
    {
        $directories = [
            __DIR__ . '/../../../src/Analyzer/Configuration',
            __DIR__ . '/../../../src/Analyzer/Security',
            __DIR__ . '/../../../src/Analyzer/Integrity',
        ];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $files = glob($directory . '/*Analyzer.php') ?: [];

            foreach ($files as $file) {
                $className = self::extractClassName($file);

                if (null === $className || !class_exists($className)) {
                    continue;
                }

                $implements = class_implements($className);

                if (!isset($implements[MetadataAnalyzerInterface::class])) {
                    continue;
                }

                $shortName = (new \ReflectionClass($className))->getShortName();
                yield $shortName => [$className];
            }
        }
    }

    private static function extractClassName(string $filePath): ?string
    {
        $content = file_get_contents($filePath);

        if (false === $content) {
            return null;
        }

        if (1 !== preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch)) {
            return null;
        }

        if (1 !== preg_match('/class\s+(\w+)/', $content, $classMatch)) {
            return null;
        }

        return $namespaceMatch[1] . '\\' . $classMatch[1];
    }
}
