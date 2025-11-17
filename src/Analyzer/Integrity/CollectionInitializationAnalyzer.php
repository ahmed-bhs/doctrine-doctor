<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\TraitCollectionInitializationDetector;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\PhpCodeParser;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Detects entity collections that are not properly initialized in constructors.
 * This analyzer checks entity metadata to find OneToMany and ManyToMany relations
 * that should be initialized with ArrayCollection but might not be.
 */
class CollectionInitializationAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    private readonly TraitCollectionInitializationDetector $traitDetector;

    private readonly PhpCodeParser $phpCodeParser;

    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
        /**
         * @readonly
         */
        private ?LoggerInterface $logger = null,
        ?TraitCollectionInitializationDetector $traitDetector = null,
        ?PhpCodeParser $phpCodeParser = null,
    ) {
        // Dependency Injection with fallback for backwards compatibility
        $this->phpCodeParser = $phpCodeParser ?? new PhpCodeParser($logger);
        $this->traitDetector = $traitDetector ?? new TraitCollectionInitializationDetector($this->phpCodeParser, $logger);
    }

    /**
     * @param QueryDataCollection $queryDataCollection - Not heavily used, this analyzer focuses on entity metadata
     * @return IssueCollection<IntegrityIssue>
     */
    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                try {
                    $metadataFactory = $this->entityManager->getMetadataFactory();
                    $allMetadata     = $metadataFactory->getAllMetadata();

                    Assert::isIterable($allMetadata, '$allMetadata must be iterable');

                    foreach ($allMetadata as $metadata) {
                        $entityIssues = $this->analyzeEntity($metadata);

                        Assert::isIterable($entityIssues, '$entityIssues must be iterable');

                        foreach ($entityIssues as $entityIssue) {
                            yield $entityIssue;
                        }
                    }
                } catch (\Throwable $throwable) {
                    // Log error safely - avoid getMessage() which might contain non-stringable objects
                    $this->logger?->error('CollectionInitializationAnalyzer error', [
                        'exception' => $throwable::class,
                        'file' => $throwable->getFile(),
                        'line' => $throwable->getLine(),
                    ]);
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Collection Initialization Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects entity collections that are not properly initialized in constructors';
    }

    /**
     * @return array<IntegrityIssue>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {

        $issues      = [];
        $entityClass = $classMetadata->getName();

        // Check all associations
        foreach ($classMetadata->getAssociationMappings() as $fieldName => $associationMapping) {
            // Only check collection types (OneToMany and ManyToMany)
            if (!$this->isCollectionAssociation($associationMapping)) {
                continue;
            }

            // Check if entity has a constructor (check entire inheritance chain)
            $reflectionClass = $classMetadata->getReflectionClass();

            if (!$this->hasConstructorInHierarchy($reflectionClass)) {
                $issues[] = $this->createMissingConstructorIssue($entityClass, $fieldName, $associationMapping);
                continue;
            }

            // Check if collection is initialized in constructor (check entire hierarchy)
            if (!$this->isCollectionInitializedInHierarchy($reflectionClass, $fieldName)) {
                // Find the constructor to report in the issue
                $constructor = $this->findConstructorInHierarchy($reflectionClass);
                if (null !== $constructor) {
                    $issues[] = $this->createUninitializedCollectionIssue($entityClass, $fieldName, $associationMapping, $constructor);
                }
            }
        }

        return $issues;
    }

    private function isCollectionAssociation(array|object $mapping): bool
    {
        // For Doctrine ORM 3.x/4.x: check class name
        if (is_object($mapping)) {
            $className = get_class($mapping);

            return str_contains($className, 'OneToManyAssociationMapping')
                || str_contains($className, 'ManyToManyAssociationMapping')
                || str_contains($className, 'ManyToManyOwningSideMapping')
                || str_contains($className, 'ManyToManyInverseSideMapping');
        }

        // For Doctrine ORM 2.x: check 'type' key
        $type = MappingHelper::getInt($mapping, 'type');

        return ClassMetadata::ONE_TO_MANY === $type || ClassMetadata::MANY_TO_MANY === $type;
    }

    private function isCollectionInitializedInConstructor(\ReflectionMethod $reflectionMethod, string $fieldName): bool
    {
        // Use PhpCodeParser instead of complex regex patterns
        // This provides robust AST-based detection that handles:
        // - $this->field = new ArrayCollection()
        // - $this->field = []
        // - $this->initializeFieldCollection()
        // - Various formatting styles
        // - Ignores comments automatically
        if ($this->phpCodeParser->hasCollectionInitialization($reflectionMethod, $fieldName)) {
            return true;
        }

        // Check if any trait used by the class initializes this collection
        // This handles patterns like Sylius's TranslatableTrait which has its own constructor
        $declaringClass = $reflectionMethod->getDeclaringClass();
        if ($this->traitDetector->isCollectionInitializedInTraits($declaringClass, $fieldName)) {
            return true;
        }

        return false;
    }

    private function createMissingConstructorIssue(string $entityClass, string $fieldName, array|object $mapping): IntegrityIssue
    {
        $shortClassName = $this->getShortClassName($entityClass);
        $targetEntity   = $this->getShortClassName(MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown');

        return new IntegrityIssue([
            'title'       => 'Missing constructor for collection initialization in ' . $shortClassName,
            'description' => sprintf(
                'Entity "%s" has a collection property "$%s" (relation to %s) but no constructor. ' .
                'Collections must be initialized to prevent null pointer exceptions. ' .
                'Without initialization, accessing the collection will cause a fatal error.',
                $shortClassName,
                $fieldName,
                $targetEntity,
            ),
            'severity'   => 'critical',
            'suggestion' => $this->suggestionFactory->createCollectionInitialization(
                entityClass: $this->getShortClassName($entityClass),
                fieldName: $fieldName,
                hasConstructor: false,
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function createUninitializedCollectionIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        \ReflectionMethod $reflectionMethod,
    ): IntegrityIssue {
        $shortClassName = $this->getShortClassName($entityClass);
        $targetEntity   = $this->getShortClassName(MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown');

        return new IntegrityIssue([
            'title'       => sprintf('Uninitialized collection in %s::$%s', $shortClassName, $fieldName),
            'description' => sprintf(
                'Entity "%s" has a collection property "$%s" (relation to %s) that is not initialized in the constructor. ' .
                'This will cause "Call to a member function on null" errors when trying to add or access items. ' .
                'Collections should always be initialized with "new ArrayCollection()" in the constructor.',
                $shortClassName,
                $fieldName,
                $targetEntity,
            ),
            'severity'   => 'critical',
            'suggestion' => $this->suggestionFactory->createCollectionInitialization(
                entityClass: $this->getShortClassName($entityClass),
                fieldName: $fieldName,
                hasConstructor: true,
                backtrace: sprintf('%s:%d', $reflectionMethod->getFileName() ?: 'unknown', $reflectionMethod->getStartLine() ?: 0),
            ),
            'backtrace' => [
                'file' => $reflectionMethod->getFileName() ?: 'unknown',
                'line' => $reflectionMethod->getStartLine() ?: 0,
            ],
            'queries' => [],
        ]);
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }

    /**
     * Check if any class in the hierarchy has a constructor.
     */
    private function hasConstructorInHierarchy(\ReflectionClass $reflectionClass): bool
    {
        $current = $reflectionClass;

        while ($current instanceof \ReflectionClass) {
            if ($current->hasMethod('__construct')) {
                return true;
            }

            $current = $current->getParentClass();
            if (false === $current) {
                break;
            }
        }

        return false;
    }

    /**
     * Find the first constructor in the hierarchy (starting from current class).
     */
    private function findConstructorInHierarchy(\ReflectionClass $reflectionClass): ?\ReflectionMethod
    {
        $current = $reflectionClass;

        while ($current instanceof \ReflectionClass) {
            if ($current->hasMethod('__construct')) {
                return $current->getMethod('__construct');
            }

            $current = $current->getParentClass();
            if (false === $current) {
                break;
            }
        }

        return null;
    }

    /**
     * Check if collection is initialized in any constructor in the hierarchy.
     * This walks up the inheritance chain and checks each constructor.
     */
    private function isCollectionInitializedInHierarchy(\ReflectionClass $reflectionClass, string $fieldName): bool
    {
        $current = $reflectionClass;

        while ($current instanceof \ReflectionClass) {
            // Check if current class has a constructor
            if ($current->hasMethod('__construct')) {
                $constructor = $current->getMethod('__construct');

                // Check if this constructor initializes the collection
                if ($this->isCollectionInitializedInConstructor($constructor, $fieldName)) {
                    return true;
                }

                // Check if constructor calls parent::__construct()
                // If it does, we need to continue checking parent constructors
                $constructorCode = $this->extractConstructorCode($constructor);
                if (null !== $constructorCode && 1 === preg_match('/parent\s*::\s*__construct\s*\(/', $constructorCode)) {
                    // Continue to parent class
                    $current = $current->getParentClass();
                    if (false === $current) {
                        break;
                    }
                    continue;
                }

                // Constructor doesn't call parent::__construct(), stop here
                // The collection initialization must happen in this constructor or not at all
                return false;
            }

            // No constructor in current class, check parent
            $current = $current->getParentClass();
            if (false === $current) {
                break;
            }
        }

        return false;
    }

    private function extractConstructorCode(\ReflectionMethod $reflectionMethod): ?string
    {
        $filename = $reflectionMethod->getFileName();
        if (false === $filename) {
            return null;
        }

        $startLine = $reflectionMethod->getStartLine();
        $endLine   = $reflectionMethod->getEndLine();
        if (false === $startLine || false === $endLine) {
            return null;
        }

        $source = file($filename);
        if (false === $source) {
            return null;
        }

        $lineCount = $endLine - $startLine + 1;
        if ($lineCount > 500) {
            $this->logger?->warning('CollectionInitializationAnalyzer: Constructor too large - skipping', [
                'lines' => $lineCount,
                'file' => $filename,
                'startLine' => $startLine,
            ]);

            return null;
        }

        $constructorCode = implode('', array_slice($source, $startLine - 1, $lineCount));
        if (strlen($constructorCode) > 50000) {
            $this->logger?->warning('CollectionInitializationAnalyzer: Constructor code too large - skipping', [
                'bytes' => strlen($constructorCode),
                'file' => $filename,
                'startLine' => $startLine,
            ]);

            return null;
        }

        return $constructorCode;
    }
}
