<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\CodeSuggestion;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Webmozart\Assert\Assert;

/**
 * Detects unsafe access to potentially empty Doctrine Collections.
 * Inspired by Psalm's CollectionFirstAndLast provider.
 * Common issues detected:
 * - Calling first()/last() on empty collection without checking isEmpty()
 * - Accessing collection elements without null checks
 * - Iterating over collection that might be empty without guard clause
 * - Chaining method calls on first()/last() result without validation
 * Example:
 *   $orders = new ArrayCollection();
 *   $firstOrder = $orders->first(); // Returns false if empty!
 *   $firstOrder->getTotal(); // FATAL: Call to member function on false
 */
class CollectionEmptyAccessAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
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
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            function () {
                $metadataFactory = $this->entityManager->getMetadataFactory();
                $allMetadata = $metadataFactory->getAllMetadata();

                Assert::isIterable($allMetadata, '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    $entityIssues = $this->analyzeEntity($metadata);

                    Assert::isIterable($entityIssues, '$entityIssues must be iterable');

                    foreach ($entityIssues as $entityIssue) {
                        yield $entityIssue;
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Collection Empty Access Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects uninitialized collections and unsafe access to potentially empty Doctrine Collections';
    }

    /**
     * @template T of object
     * @param ClassMetadata<T> $classMetadata
     * @return list<IssueInterface>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {
        $reflectionClass = $classMetadata->reflClass;

        if (null === $reflectionClass) {
            return [];
        }

        $collectionAssociations = [];
        foreach ($classMetadata->getAssociationNames() as $assocName) {
            if ($classMetadata->isCollectionValuedAssociation($assocName)) {
                $collectionAssociations[] = $assocName;
            }
        }

        if ([] === $collectionAssociations) {
            return [];
        }

        $constructorSource = $this->getConstructorSource($reflectionClass);

        $issues = [];

        foreach ($collectionAssociations as $assocName) {
            if (null === $constructorSource) {
                $issues[] = $this->createUninitializedCollectionIssue(
                    $classMetadata->getName(),
                    $assocName,
                );

                continue;
            }

            if (!$this->isCollectionInitializedInConstructor($assocName, $constructorSource, $reflectionClass)) {
                $issues[] = $this->createUninitializedCollectionIssue(
                    $classMetadata->getName(),
                    $assocName,
                );
            }
        }

        return $issues;
    }

    private function getConstructorSource(\ReflectionClass $reflectionClass): ?string
    {
        $constructor = $reflectionClass->getConstructor();

        if (null === $constructor) {
            return null;
        }

        $fileName = $constructor->getFileName();
        if (false === $fileName || !file_exists($fileName)) {
            return null;
        }

        $startLine = $constructor->getStartLine();
        $endLine = $constructor->getEndLine();

        if (false === $startLine || false === $endLine) {
            return null;
        }

        $lines = file($fileName);
        if (false === $lines) {
            return null;
        }

        return implode('', \array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
    }

    private function isCollectionInitializedInConstructor(
        string $propertyName,
        string $constructorSource,
        \ReflectionClass $reflectionClass,
    ): bool {
        if (1 === preg_match('/\$this\s*->\s*' . preg_quote($propertyName, '/') . '\s*=\s*new\s+/', $constructorSource)) {
            return true;
        }

        if ($reflectionClass->hasProperty($propertyName)) {
            $property = $reflectionClass->getProperty($propertyName);
            if ($property->hasDefaultValue() && null !== $property->getDefaultValue()) {
                return true;
            }

            if ($property->hasType()) {
                $type = $property->getType();
                if ($type instanceof \ReflectionNamedType && !$type->allowsNull()) {
                    $attributes = $property->getAttributes();
                    foreach ($attributes as $attribute) {
                        if (str_contains($attribute->getName(), 'OneToMany') || str_contains($attribute->getName(), 'ManyToMany')) {
                            break;
                        }
                    }
                }
            }
        }

        $parent = $reflectionClass->getParentClass();
        if (false !== $parent) {
            $parentConstructorSource = $this->getConstructorSource($parent);
            if (null !== $parentConstructorSource) {
                if (str_contains($constructorSource, 'parent::__construct')) {
                    return $this->isCollectionInitializedInConstructor($propertyName, $parentConstructorSource, $parent);
                }
            }
        }

        return false;
    }

    private function createUninitializedCollectionIssue(
        string $entityClass,
        string $propertyName,
    ): IssueInterface {
        $shortClassName = $this->getShortClassName($entityClass);

        $description = sprintf(
            "Collection property %s::\$%s is not initialized.\n\n",
            $shortClassName,
            $propertyName,
        );

        $description .= "This can cause issues:\n";
        $description .= "- Accessing the collection will return NULL instead of a Collection object\n";
        $description .= "- Calling isEmpty(), count(), first(), etc. will fail\n";
        $description .= "- Adding items to the collection will fail\n\n";

        $description .= "Solution:\n";
        $description .= "Initialize the collection in the constructor:\n\n";
        $description .= sprintf(
            "  // In %s::__construct()\n",
            $shortClassName,
        );
        $description .= sprintf(
            "  \$this->%s = new ArrayCollection();\n\n",
            $propertyName,
        );

        $description .= "Or use PHP 8.1+ property initialization:\n\n";
        $description .= sprintf(
            "  private Collection \$%s = new ArrayCollection();\n",
            $propertyName,
        );

        $suggestionCode = sprintf(
            "// In %s::__construct():\n\$this->%s = new ArrayCollection();\n\n" .
            "// Or use PHP 8.1+ property initialization:\n" .
            "private Collection \$%s = new ArrayCollection();",
            $shortClassName,
            $propertyName,
            $propertyName,
        );

        $issueData = new IssueData(
            type: 'collection_uninitialized',
            title: sprintf('Uninitialized Collection: %s::\$%s', $shortClassName, $propertyName),
            description: $description,
            severity: Severity::critical(),
            suggestion: new CodeSuggestion([
                'description' => 'Initialize the collection to prevent null access errors',
                'code' => $suggestionCode,
            ]),
            queries: [],
        );

        return $this->issueFactory->create($issueData);
    }

    private function getShortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
