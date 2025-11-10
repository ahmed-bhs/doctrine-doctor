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
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;

/**
 * Detects final entity classes that can cause problems with Doctrine proxies.
 * Inspired by PHPStan's EntityNotFinalRule.
 * When an entity is marked as 'final', Doctrine cannot create proxy classes for lazy loading,
 * which will cause runtime errors when accessing uninitialized relationships.
 * Issues detected:
 * - Entity classes marked as final
 * - Final classes used in lazy-loaded relationships
 * - Proxy instantiation failures due to final modifier
 */
class FinalEntityAnalyzer implements AnalyzerInterface
{
    /** @var array<string, bool> Cache of checked entities to avoid duplicate issues */
    /** @var array<mixed> */
    private array $checkedEntities = [];

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
        //  Article pattern: Use generator instead of array
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                $this->checkedEntities = [];

                // Reset cache for each analysis
                // Get all loaded entities from metadata
                try {
                    /** @var array<ClassMetadata<object>> $allMetadata */
                    $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
                } catch (\Throwable) {
                    // Cannot load metadata, skip analysis
                    return IssueCollection::empty();
                }

                assert(is_iterable($allMetadata), '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    // Skip already checked entities
                    $entityClass = $metadata->getName();

                    if (isset($this->checkedEntities[$entityClass])) {
                        continue;
                    }

                    $this->checkedEntities[$entityClass] = true;

                    // Skip transient (non-entity) classes
                    if ($metadata->isEmbeddedClass) {
                        continue;
                    }

                    // Check if entity class is final
                    $issue = $this->checkFinalEntity($metadata);
                    if (null !== $issue) {
                        yield $issue;
                    }
                }
            },
        );
    }

    /**
     * Check if entity is final and create an issue if so.
     */
    private function checkFinalEntity(ClassMetadata $classMetadata): ?IssueInterface
    {
        $entityClass = $classMetadata->getName();

        // ClassMetadata always provides valid class name
        assert(class_exists($entityClass));
        $reflectionClass = new ReflectionClass($entityClass);

        // Entity is not final, all good
        if (!$reflectionClass->isFinal()) {
            return null;
        }

        // Build detailed description
        $description = $this->buildDescription($entityClass, $reflectionClass, $classMetadata);

        $issueData = new IssueData(
            type: 'final_entity',
            title: sprintf('Final Entity Detected: %s', $this->getShortClassName($entityClass)),
            description: $description,
            severity: $this->calculateSeverity($classMetadata),
            suggestion: null,
            queries: [],
        );

        return $this->issueFactory->create($issueData);
    }

    /**
     * Build detailed description of the issue.
     */
    private function buildDescription(string $entityClass, \ReflectionClass $reflectionClass, ClassMetadata $classMetadata): string
    {
        $shortName = $this->getShortClassName($entityClass);

        $description = sprintf(
            "Entity class %s is marked as 'final', which prevents Doctrine from creating proxy classes.

",
            $shortName,
        );

        $description .= "Why this is a problem:
";
        $description .= "- Doctrine uses proxy classes for lazy loading relationships
";
        $description .= "- Final classes cannot be extended, preventing proxy creation
";
        $description .= "- This will cause runtime errors when accessing lazy-loaded associations

";

        // Check for lazy associations
        $lazyAssociations = $this->findLazyAssociations($classMetadata);

        if ([] !== $lazyAssociations) {
            $description .= sprintf(
                "This entity has %d lazy-loaded association(s) that will fail:
",
                count($lazyAssociations),
            );

            assert(is_iterable($lazyAssociations), '$lazyAssociations must be iterable');

            foreach ($lazyAssociations as $assocName => $targetEntity) {
                $description .= sprintf(
                    "  - %s (-> %s)
",
                    $assocName,
                    $this->getShortClassName($targetEntity),
                );
            }

            $description .= "
";
        }

        $description .= "Solution:
";
        $description .= "Remove the 'final' keyword from the class definition:

";
        $description .= sprintf(
            "  // File: %s
",
            $reflectionClass->getFileName() ?: 'unknown',
        );
        $description .= sprintf(
            "  - final class %s { ... }
",
            $shortName,
        );
        $description .= sprintf(
            "  + class %s { ... }

",
            $shortName,
        );

        $description .= "Alternative (if you need immutability):
";
        $description .= "- Mark individual methods as final instead of the class
";
        $description .= "- Use readonly properties (PHP 8.1+) for immutability
";

        return $description . "- Consider using eager loading (fetch=\"EAGER\") if you don't need proxies";
    }

    /**
     * Find lazy-loaded associations in the entity.
     * @return array<string, string> Association name => Target entity
     */
    private function findLazyAssociations(ClassMetadata $classMetadata): array
    {

        $lazyAssociations = [];

        foreach ($classMetadata->getAssociationNames() as $assocName) {
            $mapping = $classMetadata->getAssociationMapping($assocName);

            // Check if fetch mode is LAZY (default)
            // LAZY = 2, EAGER = 3, EXTRA_LAZY = 4
            if (($mapping['fetch'] ?? ClassMetadata::FETCH_LAZY) === ClassMetadata::FETCH_LAZY) {
                $lazyAssociations[$assocName] = $mapping['targetEntity'];
            }
        }

        return $lazyAssociations;
    }

    /**
     * Calculate severity based on number of lazy associations.
     */
    private function calculateSeverity(ClassMetadata $classMetadata): Severity
    {
        $lazyAssociations = $this->findLazyAssociations($classMetadata);

        if ([] !== $lazyAssociations) {
            // Critical if entity has lazy associations (will definitely break)
            return Severity::critical();
        }

        // Warning if no lazy associations (might still cause issues)
        return Severity::warning();
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
