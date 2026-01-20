<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Helper;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\PhpCodeParser;
use Psr\Log\LoggerInterface;
use ReflectionClass;

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
    private readonly PhpCodeParser $phpCodeParser;

    public function __construct(
        ?PhpCodeParser $phpCodeParser = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->phpCodeParser = $phpCodeParser ?? new PhpCodeParser($logger);
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
                if ($this->doesTraitInitializeCollection($trait, $fieldName, $reflectionClass)) {
                    return true;
                }

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
     * 3. Aliased trait constructor called from class constructor
     *
     * @param ReflectionClass<object> $trait The trait to check
     * @param string $fieldName The field name to look for
     * @param ReflectionClass<object>|null $usingClass The class using the trait (for alias detection)
     * @return bool True if the trait initializes this field
     */
    private function doesTraitInitializeCollection(ReflectionClass $trait, string $fieldName, ?ReflectionClass $usingClass = null): bool
    {
        try {
            if (!$trait->hasMethod('__construct')) {
                return false;
            }

            $traitConstructor = $trait->getMethod('__construct');

            if ($this->phpCodeParser->hasCollectionInitialization($traitConstructor, $fieldName)) {
                if (null !== $usingClass) {
                    return $this->isTraitConstructorCalled($usingClass, $trait);
                }
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
     * Check if a trait's constructor is called from the using class.
     *
     * This detects patterns like:
     * - Trait constructor aliased: `use TranslatableTrait { __construct as initTranslations; }`
     *   and called: `$this->initTranslations();`
     * - Direct call if not conflicting
     *
     * @param ReflectionClass<object> $usingClass The class using the trait
     * @param ReflectionClass<object> $trait The trait being used
     * @return bool True if the trait constructor is called
     */
    private function isTraitConstructorCalled(ReflectionClass $usingClass, ReflectionClass $trait): bool
    {
        if (!$usingClass->hasMethod('__construct')) {
            return true;
        }

        $classConstructor = $usingClass->getMethod('__construct');

        $filename = $classConstructor->getFileName();
        if (false === $filename) {
            return true; // Assume it's called if we can't check
        }

        $startLine = $classConstructor->getStartLine();
        $endLine = $classConstructor->getEndLine();
        if (false === $startLine || false === $endLine) {
            return true;
        }

        $source = file($filename);
        if (false === $source) {
            return true;
        }

        $constructorCode = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

        $traitName = $trait->getShortName();

        $patterns = [
            '/\$this\s*->\s*init' . preg_quote($traitName, '/') . '\s*\(/i',
            '/\$this\s*->\s*initialize' . preg_quote($traitName, '/') . '\s*\(/i',
            '/\$this\s*->\s*' . lcfirst($traitName) . '__construct\s*\(/i',
        ];

        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $constructorCode)) {
                return true;
            }
        }

        $traitAliases = $this->getTraitAliases($usingClass, $trait);
        foreach ($traitAliases as $alias) {
            if (str_contains($constructorCode, '$this->' . $alias . '(')) {
                return true;
            }
        }

        return true;
    }

    /**
     * Get method aliases for a trait in a class.
     * This parses the class file to find `use Trait { method as alias }` patterns.
     *
     * @param ReflectionClass<object> $usingClass
     * @param ReflectionClass<object> $trait
     * @return array<string> List of aliases for __construct
     */
    private function getTraitAliases(ReflectionClass $usingClass, ReflectionClass $trait): array
    {
        $aliases = [];
        $filename = $usingClass->getFileName();
        if (false === $filename) {
            return $aliases;
        }

        $source = file_get_contents($filename);
        if (false === $source) {
            return $aliases;
        }

        $traitName = $trait->getShortName();

        $pattern = '/' . preg_quote($traitName, '/') . '\s*::\s*__construct\s+as\s+(?:private\s+|protected\s+|public\s+)?(\w+)/i';

        if (preg_match_all($pattern, $source, $matches) > 0) {
            $aliases = $matches[1];
        }

        return $aliases;
    }
}
