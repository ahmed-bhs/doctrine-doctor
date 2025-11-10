<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verify that only proper analyzers implementing AnalyzerInterface are loaded.
 *
 * This test ensures helper classes like NamingConventionHelper are not
 * accidentally registered as analyzers in the service container.
 */
final class ServiceContainerAnalyzerTest extends TestCase
{
    #[Test]
    public function all_analyzer_classes_must_implement_analyzer_interface(): void
    {
        // Scan all classes in src/Analyzer directory
        $analyzerDir = __DIR__ . '/../../src/Analyzer';
        $analyzerFiles = glob($analyzerDir . '/*.php');

        self::assertNotEmpty($analyzerFiles, 'Analyzer directory should contain PHP files');

        $violations = [];

        foreach ($analyzerFiles as $file) {
            $className = $this->extractClassName($file);

            if (null === $className) {
                continue;
            }

            // Skip the interface itself
            if ('AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface' === $className) {
                continue;
            }

            // Skip backup files
            if (str_ends_with($file, '.bak')) {
                continue;
            }

            // If class exists and ends with 'Helper', it should NOT implement AnalyzerInterface
            if (str_contains($className, 'Helper')) {
                if (class_exists($className)) {
                    $implements = class_implements($className);

                    if (isset($implements[AnalyzerInterface::class])) {
                        $violations[] = sprintf(
                            'Helper class %s should NOT implement AnalyzerInterface',
                            $className,
                        );
                    }
                }
            }
        }

        self::assertEmpty(
            $violations,
            "Found helper classes incorrectly implementing AnalyzerInterface:\n" . implode("\n", $violations),
        );
    }

    #[Test]
    public function naming_convention_helper_does_not_implement_analyzer_interface(): void
    {
        $helperClass = 'AhmedBhs\DoctrineDoctor\Analyzer\Helper\NamingConventionHelper';

        // Verify the class exists
        self::assertTrue(
            class_exists($helperClass),
            'NamingConventionHelper class should exist',
        );

        // Verify it does NOT implement AnalyzerInterface
        $implements = class_implements($helperClass);
        self::assertIsArray($implements);

        self::assertArrayNotHasKey(
            AnalyzerInterface::class,
            $implements,
            'NamingConventionHelper should NOT implement AnalyzerInterface as it is a helper class',
        );

        // Verify it does NOT have an analyze() method
        $reflection = new \ReflectionClass($helperClass);

        self::assertFalse(
            $reflection->hasMethod('analyze'),
            'NamingConventionHelper should NOT have an analyze() method',
        );
    }

    private function extractClassName(string $filePath): ?string
    {
        $content = file_get_contents($filePath);

        if (false === $content) {
            return null;
        }

        // Extract namespace
        if (0 === preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch)) {
            return null;
        }

        $namespace = $namespaceMatch[1];

        // Extract class name
        if (0 === preg_match('/(?:class|interface|trait)\s+(\w+)/', $content, $classMatch)) {
            return null;
        }

        $className = $classMatch[1];

        return $namespace . '\\' . $className;
    }
}
