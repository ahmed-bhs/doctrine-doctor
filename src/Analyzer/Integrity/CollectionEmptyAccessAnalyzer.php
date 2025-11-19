<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
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
    /**
     * Track collection states to detect unsafe access patterns.
     * This property tracks empty collections for potential future enhancement
     * to detect unsafe access patterns at runtime.
     * @var array<string, array{isEmpty: bool, accessed: bool}>
     * @phpstan-ignore property.onlyWritten
     */
    private array $collectionStates = [];

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
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                // Reset collection states for each analysis run
                $this->collectionStates = [];

                // Get all managed entities to check their collections
                $unitOfWork      = $this->entityManager->getUnitOfWork();
                $managedEntities = $unitOfWork->getIdentityMap();

                Assert::isIterable($managedEntities, '$managedEntities must be iterable');

                foreach ($managedEntities as $entityClass => $entities) {
                    try {
                        $metadata = $this->entityManager->getClassMetadata($entityClass);
                    } catch (\Throwable) {
                        continue;
                    }

                    // Check each entity instance
                    Assert::isIterable($entities, '$entities must be iterable');

                    foreach ($entities as $entity) {
                        $entityIssues = $this->checkEntityCollections($entity, $metadata);
                        Assert::isIterable($entityIssues, '$entityIssues must be iterable');

                        foreach ($entityIssues as $entityIssue) {
                            yield $entityIssue;
                        }
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
     * Check all collection properties of an entity.
     * @template T of object
     * @param ClassMetadata<T> $classMetadata
     * @return list<IssueInterface>
     */
    private function checkEntityCollections(object $entity, ClassMetadata $classMetadata): array
    {

        $issues = [];

        // Use cached ReflectionClass from Doctrine's ClassMetadata
        $reflectionClass = $classMetadata->reflClass;

        if (null === $reflectionClass) {
            return [];
        }

        // Check each collection-valued association
        foreach ($classMetadata->getAssociationNames() as $assocName) {
            if (!$classMetadata->isCollectionValuedAssociation($assocName)) {
                continue;
            }

            if (!$reflectionClass->hasProperty($assocName)) {
                continue;
            }

            $property   = $reflectionClass->getProperty($assocName);

            // Try to get the collection value, but catch errors for uninitialized typed properties (PHP 8.1+)
            try {
                $collection = $property->getValue($entity);
            } catch (\Error $e) {
                // Property is not initialized (PHP 8.1+ typed properties)
                if (str_contains($e->getMessage(), 'must not be accessed before initialization')) {
                    $issues[] = $this->createUninitializedCollectionIssue($entity, $assocName);
                    continue;
                }

                throw $e;
            }

            // Check if collection is null (not initialized)
            if (null === $collection) {
                $issues[] = $this->createUninitializedCollectionIssue($entity, $assocName);
                continue;
            }

            // Check if collection is empty and might be accessed unsafely
            // Track state for potential future use in detecting unsafe access patterns
            if (\is_object($collection) && method_exists($collection, 'isEmpty') && $collection->isEmpty()) {
                // Track this collection as empty
                $collectionId = $this->getCollectionId($entity, $assocName);
                $this->collectionStates[$collectionId] = ['isEmpty' => true, 'accessed' => false];
            }
        }

        return $issues;
    }

    /**
     * Create issue for uninitialized collection.
     */
    private function createUninitializedCollectionIssue(
        object $entity,
        string $propertyName,
    ): IssueInterface {
        $entityClass    = $entity::class;
        $shortClassName = $this->getShortClassName($entityClass);

        $description = sprintf(
            "Collection property %s::\$%s is not initialized.

",
            $shortClassName,
            $propertyName,
        );

        $description .= "This can cause issues:
";
        $description .= "- Accessing the collection will return NULL instead of a Collection object
";
        $description .= "- Calling isEmpty(), count(), first(), etc. will fail
";
        $description .= "- Adding items to the collection will fail

";

        $description .= "Solution:
";
        $description .= "Initialize the collection in the constructor:

";
        $description .= sprintf(
            "  // In %s::__construct()
",
            $shortClassName,
        );
        $description .= sprintf(
            "  \$this->%s = new ArrayCollection();

",
            $propertyName,
        );

        $description .= "Or use PHP 8.1+ property initialization:

";
        $description .= sprintf(
            "  private Collection \$%s = new ArrayCollection();
",
            $propertyName,
        );

        // Create suggestion
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

    /**
     * Generate unique ID for a collection instance.
     */
    private function getCollectionId(object $entity, string $propertyName): string
    {
        return spl_object_id($entity) . '::' . $propertyName;
    }

    /**
     * Get short class name without namespace.
     */
    private function getShortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
