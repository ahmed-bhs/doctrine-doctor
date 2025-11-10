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
use ReflectionMethod;

/**
 * Detects Doctrine Embeddables that are not immutable.
 * Embeddables should be implemented as immutable Value Objects because:
 * - Value Objects represent concepts without identity
 * - Immutability prevents unexpected side effects
 * - Enables safe sharing between multiple entities
 * - Simplifies reasoning about state changes
 * - Aligns with Domain-Driven Design principles
 * This analyzer detects:
 * - Public setter methods (setXxx)
 * - Non-readonly properties (PHP 8.1+)
 * - Public mutable properties
 * - Methods that modify internal state
 * Immutable embeddables should:
 * - Initialize all properties in constructor
 * - Have no setter methods
 * - Use readonly properties (PHP 8.1+) or private properties
 * - Return new instances when "changing" values
 */
class EmbeddableMutabilityAnalyzer implements AnalyzerInterface
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
        $className       = $classMetadata->getName();
        assert(class_exists($className));
        $reflectionClass = new ReflectionClass($className);

        $mutabilityIssues = $this->detectMutabilityIssues($reflectionClass);

        if ($this->hasMutabilityIssues($mutabilityIssues)) {
            return [$this->createMutabilityIssue($classMetadata, $mutabilityIssues)];
        }

        return [];
    }

    /**
     * Detect all mutability issues in an embeddable class.
     * @return array<string, array<string>>
     */
    private function detectMutabilityIssues(ReflectionClass $reflectionClass): array
    {
        return [
            'setters'                => $this->detectSetterMethods($reflectionClass),
            'publicProperties'       => $this->detectPublicProperties($reflectionClass),
            'nonReadonlyProperties'  => $this->detectNonReadonlyProperties($reflectionClass),
        ];
    }

    /**
     * Detect setter methods in the class.
     * @return array<string>
     */
    private function detectSetterMethods(ReflectionClass $reflectionClass): array
    {
        $setters = [];

        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            if ($this->isSetterMethod($reflectionMethod)) {
                $setters[] = $reflectionMethod->getName();
            }
        }

        return $setters;
    }

    /**
     * Detect public properties in the class.
     * @return array<string>
     */
    private function detectPublicProperties(ReflectionClass $reflectionClass): array
    {
        $publicProperties = [];

        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            if ($reflectionProperty->isStatic()) {
                continue;
            }

            if ($reflectionProperty->isPublic()) {
                $publicProperties[] = $reflectionProperty->getName();
            }
        }

        return $publicProperties;
    }

    /**
     * Detect non-readonly properties (PHP 8.1+).
     * @return array<string>
     */
    private function detectNonReadonlyProperties(ReflectionClass $reflectionClass): array
    {
        $nonReadonlyProperties = [];

        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            if ($reflectionProperty->isStatic()) {
                continue;
            }

            if ($reflectionProperty->isPublic()) {
                continue;
            }

            if (!$reflectionProperty->isReadOnly()) {
                $nonReadonlyProperties[] = $reflectionProperty->getName();
            }
        }

        return $nonReadonlyProperties;
    }

    /**
     * Check if any mutability issues were detected.
     * @param array<string, array<string>> $mutabilityIssues
     */
    private function hasMutabilityIssues(array $mutabilityIssues): bool
    {
        return [] !== $mutabilityIssues['setters']
            || [] !== $mutabilityIssues['publicProperties']
            || [] !== $mutabilityIssues['nonReadonlyProperties'];
    }

    private function isSetterMethod(ReflectionMethod $reflectionMethod): bool
    {
        $methodName = $reflectionMethod->getName();

        // Check if method starts with 'set'
        if (!str_starts_with($methodName, 'set')) {
            return false;
        }

        // Must have at least one parameter
        if ($reflectionMethod->getNumberOfParameters() < 1) {
            return false;
        }

        // Exclude __set magic method
        return '__set' !== $methodName;
    }

    /**
     * @param array<string, array<string>> $mutabilityIssues
     */
    private function createMutabilityIssue(
        ClassMetadata $classMetadata,
        array $mutabilityIssues,
    ): IssueInterface {
        $className      = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $issueDetails = [];

        if ([] !== $mutabilityIssues['setters']) {
            $issueDetails[] = sprintf(
                'Setter methods found: %s',
                implode(', ', $mutabilityIssues['setters']),
            );
        }

        if ([] !== $mutabilityIssues['publicProperties']) {
            $issueDetails[] = sprintf(
                'Public mutable properties: $%s',
                implode(', $', $mutabilityIssues['publicProperties']),
            );
        }

        if ([] !== $mutabilityIssues['nonReadonlyProperties']) {
            $issueDetails[] = sprintf(
                'Non-readonly properties: $%s (consider using readonly keyword in PHP 8.1+)',
                implode(', $', $mutabilityIssues['nonReadonlyProperties']),
            );
        }

        $description = sprintf(
            'Embeddable %s is not immutable. ' .
            'Embeddables should be implemented as immutable Value Objects to prevent side effects, ' .
            'enable safe sharing between entities, and align with Domain-Driven Design principles. ' .
            "\n\nIssues detected:\n- %s",
            $shortClassName,
            implode("\n- ", $issueDetails),
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'embeddable_mutability',
            'title'       => sprintf('Mutable Embeddable: %s', $shortClassName),
            'description' => $description,
            'severity'    => 'warning',
            'category'    => 'code_quality',
            'suggestion'  => $this->createImmutabilitySuggestion($shortClassName, $mutabilityIssues),
            'backtrace'   => [
                'embeddable'        => $className,
                'mutability_issues' => $mutabilityIssues,
            ],
        ]);
    }

    /**
     * @param array<string, array<string>> $mutabilityIssues
     */
    private function createImmutabilitySuggestion(
        string $className,
        array $mutabilityIssues,
    ): SuggestionInterface {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'embeddable_mutability',
            context: [
                'embeddable_class'  => $className,
                'mutability_issues' => $mutabilityIssues,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::codeQuality(),
                severity: Severity::warning(),
                title: sprintf('Make %s Immutable', $className),
                tags: ['embeddable', 'immutability', 'value-object', 'ddd'],
            ),
        );
    }
}
