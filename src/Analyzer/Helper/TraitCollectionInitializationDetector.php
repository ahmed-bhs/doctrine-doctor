<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
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

            // Use PhpCodeParser for robust AST-based detection
            // This replaces fragile regex with proper PHP parsing
            if ($this->phpCodeParser->hasCollectionInitialization($traitConstructor, $fieldName)) {
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
}
