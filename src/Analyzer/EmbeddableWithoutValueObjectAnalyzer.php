<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;

/**
 * Detects Embeddables that don't implement typical Value Object methods.
 * Value Objects should implement methods that reflect their nature:
 * - equals(): Compare value equality (not identity)
 * - __toString(): String representation for debugging/display
 * - Validation logic in constructor
 * - Named constructors for different creation scenarios
 * - Domain-specific methods (e.g., Money::add(), Money::subtract())
 * Benefits of implementing these methods:
 * - Better testability and debugging
 * - Type-safe comparisons
 * - Rich domain model
 * - Self-validating objects
 * - More expressive code
 * This analyzer checks for missing:
 * - equals() method for value comparison
 * - __toString() method for string representation
 * - Constructor validation (heuristic: check for exceptions)
 */
class EmbeddableWithoutValueObjectAnalyzer implements AnalyzerInterface
{
    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private IssueFactoryInterface $issueFactory,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                $classMetadataFactory = $this->entityManager->getMetadataFactory();

                foreach ($classMetadataFactory->getAllMetadata() as $classMetadatum) {
                    // Only analyze embeddables
                    if (!$classMetadatum->isEmbeddedClass) {
                        continue;
                    }

                    $entityIssues = $this->analyzeEmbeddable($classMetadatum);

                    assert(is_iterable($entityIssues), '$entityIssues must be iterable');

                    foreach ($entityIssues as $entityIssue) {
                        yield $entityIssue;
                    }
                }
            },
        );
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     * @return array<\AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function analyzeEmbeddable(ClassMetadata $classMetadata): array
    {
        $issues          = [];
        $className       = $classMetadata->getName();
        assert(class_exists($className));
        $reflectionClass = new ReflectionClass($className);

        $missingMethods = [];

        // Check for equals() method
        if (!$reflectionClass->hasMethod('equals')) {
            $missingMethods[] = 'equals()';
        }

        // Check for __toString() method
        if (!$reflectionClass->hasMethod('__toString')) {
            $missingMethods[] = '__toString()';
        }

        // Check for constructor validation (heuristic)
        if ($reflectionClass->hasMethod('__construct')) {
            $constructor = $reflectionClass->getMethod('__construct');
            $source      = $constructor->getFileName();

            if (false !== $source) {
                $constructorCode = $this->getMethodSource($constructor);

                // Heuristic: check if constructor throws exceptions or has validation
                $hasValidation = str_contains($constructorCode, 'throw')
                    || str_contains($constructorCode, 'InvalidArgumentException')
                    || str_contains($constructorCode, 'Assert')
                    || str_contains($constructorCode, 'if (');

                if (!$hasValidation) {
                    $missingMethods[] = 'constructor validation';
                }
            }
        }

        // Create issue if methods are missing
        if ([] !== $missingMethods) {
            $issues[] = $this->createMissingMethodsIssue($classMetadata, $missingMethods);
        }

        return $issues;
    }

    private function getMethodSource(\ReflectionMethod $reflectionMethod): string
    {
        $filename = $reflectionMethod->getFileName();

        if (false === $filename) {
            return '';
        }

        $startLine = $reflectionMethod->getStartLine();
        $endLine   = $reflectionMethod->getEndLine();

        if (false === $startLine || false === $endLine) {
            return '';
        }

        $fileContents = file($filename);

        if (false === $fileContents) {
            return '';
        }

        $methodLines = array_slice($fileContents, $startLine - 1, $endLine - $startLine + 1);

        return implode('', $methodLines);
    }

    /**
     * @param array<string> $missingMethods
     */
    private function createMissingMethodsIssue(
        ClassMetadata $classMetadata,
        array $missingMethods,
    ): IssueInterface {
        $className      = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $description = sprintf(
            'Embeddable %s does not implement typical Value Object methods. ' .
            'Value Objects should have methods like equals() for comparison, __toString() for representation, ' .
            'and constructor validation to ensure integrity. ' .
            "\n\nMissing methods:\n- %s",
            $shortClassName,
            implode("\n- ", $missingMethods),
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'embeddable_without_value_object_methods',
            'title'       => sprintf('Incomplete Value Object: %s', $shortClassName),
            'description' => $description,
            'severity'    => 'info',
            'category'    => 'code_quality',
            'suggestion'  => $this->createValueObjectSuggestion($shortClassName, $missingMethods),
            'backtrace'   => [
                'embeddable'      => $className,
                'missing_methods' => $missingMethods,
            ],
        ]);
    }

    /**
     * @param array<string> $missingMethods
     */
    private function createValueObjectSuggestion(
        string $className,
        array $missingMethods,
    ): SuggestionInterface {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'embeddable_value_object_methods',
            context: [
                'embeddable_class' => $className,
                'missing_methods'  => $missingMethods,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::codeQuality(),
                severity: Severity::info(),
                title: sprintf('Add Value Object Methods to %s', $className),
                tags: ['embeddable', 'value-object', 'ddd', 'best-practices'],
            ),
        );
    }
}
