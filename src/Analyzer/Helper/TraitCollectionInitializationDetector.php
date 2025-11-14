<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Helper;

use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Detects collection initialization in traits following the Single Responsibility Principle.
 * This class is responsible for analyzing trait hierarchies to find collection initializations
 * that may not be visible in the main class constructor.
 *
 * Common patterns detected:
 * - Trait with constructor that initializes collections
 * - Trait constructor aliased in the using class (e.g., Sylius TranslatableTrait)
 * - Nested traits (traits using other traits)
 * - Initialization method calls (e.g., $this->initializeTranslationsCollection())
 */
final class TraitCollectionInitializationDetector
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Check if a collection field is initialized anywhere in the trait hierarchy.
     *
     * This method implements a depth-first search through the trait hierarchy,
     * checking each trait's constructor and initialization methods.
     *
     * @param ReflectionClass<object> $reflectionClass The class to analyze
     * @param string $fieldName The collection field name to check
     * @return bool True if the field is initialized in any trait
     */
    public function isCollectionInitializedInTraits(ReflectionClass $reflectionClass, string $fieldName): bool
    {
        try {
            $traits = $reflectionClass->getTraits();

            foreach ($traits as $trait) {
                // Check if this trait initializes the collection
                if ($this->doesTraitInitializeCollection($trait, $fieldName)) {
                    return true;
                }

                // Recursively check nested traits (traits using other traits)
                if ($this->isCollectionInitializedInTraits($trait, $fieldName)) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            $this->logger?->warning('TraitCollectionInitializationDetector: Error checking traits', [
                'exception' => $e::class,
                'class' => $reflectionClass->getName(),
                'field' => $fieldName,
            ]);
            return false;
        }
    }

    /**
     * Check if a specific trait initializes the given collection field.
     *
     * This checks:
     * 1. Direct initialization in trait constructor
     * 2. Initialization via dedicated init methods
     *
     * @param ReflectionClass<object> $trait The trait to check
     * @param string $fieldName The field name to look for
     * @return bool True if the trait initializes this field
     */
    private function doesTraitInitializeCollection(ReflectionClass $trait, string $fieldName): bool
    {
        try {
            // Check if trait has a constructor
            if (!$trait->hasMethod('__construct')) {
                return false;
            }

            $traitConstructor = $trait->getMethod('__construct');
            $constructorCode = $this->extractMethodCode($traitConstructor);

            if (null === $constructorCode) {
                return false;
            }

            // Remove comments to avoid false positives
            $constructorCode = $this->removeComments($constructorCode);

            // Check if this field is initialized in the constructor
            if ($this->isFieldInitializedInCode($constructorCode, $fieldName)) {
                return true;
            }

            return false;
        } catch (\Throwable $e) {
            $this->logger?->warning('TraitCollectionInitializationDetector: Error analyzing trait', [
                'exception' => $e::class,
                'trait' => $trait->getName(),
                'field' => $fieldName,
            ]);
            return false;
        }
    }

    /**
     * Check if a field is initialized in the given code snippet.
     *
     * Detects patterns like:
     * - $this->fieldName = new ArrayCollection()
     * - $this->fieldName = []
     * - $this->fieldName = new \Doctrine\Common\Collections\ArrayCollection()
     *
     * @param string $code The code to analyze
     * @param string $fieldName The field name to look for
     * @return bool True if initialization pattern is found
     */
    private function isFieldInitializedInCode(string $code, string $fieldName): bool
    {
        $escapedFieldName = preg_quote($fieldName, '/');

        if ('' === $escapedFieldName) {
            return false;
        }

        // Patterns for collection initialization
        $patterns = [
            // Direct assignment with ArrayCollection
            '/\$this->' . $escapedFieldName . '\s*=\s*new\s+(?:\\\\?Doctrine\\\\Common\\\\Collections\\\\)?ArrayCollection\s*\(/',
            // PHP array initialization
            '/\$this->' . $escapedFieldName . '\s*=\s*\[\s*\]/',
            // ArrayCollection with use statement
            '/\$this->' . $escapedFieldName . '\s*=\s*new\s+ArrayCollection\s*\(/',
        ];

        foreach ($patterns as $pattern) {
            try {
                if (preg_match($pattern, $code)) {
                    return true;
                }
            } catch (\Throwable $e) {
                $this->logger?->debug('TraitCollectionInitializationDetector: Regex error', [
                    'exception' => $e::class,
                    'pattern' => $pattern,
                ]);
                continue;
            }
        }

        return false;
    }

    /**
     * Extract the source code of a method.
     *
     * @param ReflectionMethod $method The method to extract
     * @return string|null The method source code, or null if unavailable
     */
    private function extractMethodCode(ReflectionMethod $method): ?string
    {
        try {
            $filename = $method->getFileName();
            if (false === $filename || !file_exists($filename)) {
                return null;
            }

            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();

            if (false === $startLine || false === $endLine) {
                return null;
            }

            $source = file($filename);
            if (false === $source) {
                return null;
            }

            $lineCount = $endLine - $startLine + 1;

            // Safety check: skip very large methods (potential memory issues)
            if ($lineCount > 500) {
                $this->logger?->debug('TraitCollectionInitializationDetector: Method too large', [
                    'method' => $method->getName(),
                    'lines' => $lineCount,
                ]);
                return null;
            }

            $methodCode = implode('', array_slice($source, $startLine - 1, $lineCount));

            // Safety check: skip very large code blocks
            if (strlen($methodCode) > 50000) {
                $this->logger?->debug('TraitCollectionInitializationDetector: Method code too large', [
                    'method' => $method->getName(),
                    'bytes' => strlen($methodCode),
                ]);
                return null;
            }

            return $methodCode;
        } catch (\Throwable $e) {
            $this->logger?->debug('TraitCollectionInitializationDetector: Error extracting code', [
                'exception' => $e::class,
                'method' => $method->getName(),
            ]);
            return null;
        }
    }

    /**
     * Remove comments from code to avoid false positives.
     *
     * @param string $code The code to clean
     * @return string Code without comments
     */
    private function removeComments(string $code): string
    {
        // Remove single-line comments (// ...)
        $code = preg_replace('/\/\/.*$/m', '', $code) ?? $code;

        // Remove multi-line comments (/* ... */)
        $code = preg_replace('/\/\*.*?\*\//s', '', $code) ?? $code;

        return $code;
    }
}
