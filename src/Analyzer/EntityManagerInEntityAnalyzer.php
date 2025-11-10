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
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\CodeQualityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;

/**
 * Detects EntityManager injection in entity classes.
 * This is a CRITICAL anti-pattern that violates:
 * - Separation of Concerns (domain vs infrastructure)
 * - Dependency Inversion Principle (domain depends on infrastructure)
 * - Single Responsibility Principle (entity manages both state and persistence)
 * - Testability (impossible to test without database)
 * Example BAD code:
 * ```php
 * class Order {
 *     public function __construct(private EntityManagerInterface $em,
        private readonly ?LoggerInterface $logger = null) {}
 *     public function addItem(OrderItem $item) {
 *         $this->items->add($item);
 *         $this->em->persist($item); // Persistence in domain
 *         $this->em->flush();
 *     }
 * }
 * ```
 * Entities should be pure domain models with no infrastructure dependencies.
 */
class EntityManagerInEntityAnalyzer implements AnalyzerInterface
{
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
    ) {
    }

    /**
     * @param QueryDataCollection $queryDataCollection - Not used, this analyzer focuses on entity metadata
     * @return IssueCollection<CodeQualityIssue>
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

                    assert(is_iterable($allMetadata), '$allMetadata must be iterable');

                    foreach ($allMetadata as $metadata) {
                        $entityIssues = $this->analyzeEntity($metadata);

                        assert(is_iterable($entityIssues), '$entityIssues must be iterable');

                        foreach ($entityIssues as $entityIssue) {
                            yield $entityIssue;
                        }
                    }
                } catch (\Throwable $throwable) {
                    $this->logger?->error('EntityManagerInEntityAnalyzer failed', [
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                        'file' => $throwable->getFile(),
                        'line' => $throwable->getLine(),
                    ]);
                }
            },
        );
    }

    /**
     * @return array<CodeQualityIssue>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {

        $issues          = [];
        $entityClass     = $classMetadata->getName();
        $reflectionClass = $classMetadata->getReflectionClass();

        // Check 1: EntityManager in constructor parameters
        if ($reflectionClass->hasMethod('__construct')) {
            $constructor     = $reflectionClass->getMethod('__construct');
            $emInConstructor = $this->hasEntityManagerParameter($constructor);

            if ($emInConstructor) {
                $issues[] = $this->createEntityManagerInConstructorIssue($entityClass, $constructor);
            }
        }

        // Check 2: EntityManager as property (injected or created)
        $emProperties = $this->findEntityManagerProperties($reflectionClass);

        assert(is_iterable($emProperties), '$emProperties must be iterable');

        foreach ($emProperties as $emProperty) {
            $issue = $this->createEntityManagerPropertyIssue($entityClass, $emProperty);
            if ($issue instanceof CodeQualityIssue) {
                $issues[] = $issue;
            }
        }

        // Check 3: EntityManager usage in methods (flush, persist, etc.)
        $methodsUsingEM = $this->findMethodsUsingEntityManager($reflectionClass);

        assert(is_iterable($methodsUsingEM), '$methodsUsingEM must be iterable');

        foreach ($methodsUsingEM as $methodUsing) {
            $issue = $this->createEntityManagerUsageIssue($entityClass, $methodUsing);
            if ($issue instanceof CodeQualityIssue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    private function hasEntityManagerParameter(\ReflectionMethod $reflectionMethod): bool
    {
        foreach ($reflectionMethod->getParameters() as $reflectionParameter) {
            $type = $reflectionParameter->getType();

            if (null === $type) {
                continue;
            }

            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';

            // Check for EntityManagerInterface or EntityManager
            if ($this->isEntityManagerType($typeName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<\ReflectionProperty>
     */
    private function findEntityManagerProperties(\ReflectionClass $reflectionClass): array
    {

        $emProperties = [];

        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            // Check property type hint
            $type = $reflectionProperty->getType();

            if ($type instanceof \ReflectionNamedType) {
                $typeName = $type->getName();

                if ($this->isEntityManagerType($typeName)) {
                    $emProperties[] = $reflectionProperty;
                    continue;
                }
            }

            // Check property name
            $propertyName = $reflectionProperty->getName();

            if ($this->isEntityManagerPropertyName($propertyName)) {
                $emProperties[] = $reflectionProperty;
            }
        }

        return $emProperties;
    }

    /**
     * @return list<\ReflectionMethod>
     */
    private function findMethodsUsingEntityManager(\ReflectionClass $reflectionClass): array
    {

        $methodsUsingEM = [];

        foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $reflectionMethod) {
            // Skip constructor (already checked)
            if ('__construct' === $reflectionMethod->getName()) {
                continue;
            }

            // Skip inherited methods from base classes
            if ($reflectionMethod->getDeclaringClass()->getName() !== $reflectionClass->getName()) {
                continue;
            }

            $filename = $reflectionMethod->getFileName();

            if (false === $filename) {
                continue;
            }

            $startLine = $reflectionMethod->getStartLine();
            $endLine   = $reflectionMethod->getEndLine();

            if (false === $startLine) {
                continue;
            }

            if (false === $endLine) {
                continue;
            }

            $source = file($filename);

            if (false === $source) {
                continue;
            }

            $methodCode = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

            // Check for EntityManager method calls
            $emPatterns = [
                '/\$this->em->flush\(\)/',
                '/\$this->em->persist\(/',
                '/\$this->em->remove\(/',
                '/\$this->entityManager->flush\(\)/',
                '/\$this->entityManager->persist\(/',
                '/\$this->entityManager->remove\(/',
                '/\$em->flush\(\)/',
                '/\$em->persist\(/',
            ];

            assert(is_iterable($emPatterns), '$emPatterns must be iterable');

            foreach ($emPatterns as $emPattern) {
                if (1 === preg_match($emPattern, $methodCode)) {
                    $methodsUsingEM[] = $reflectionMethod;
                    break;
                }
            }
        }

        return $methodsUsingEM;
    }

    private function isEntityManagerType(string $typeName): bool
    {
        $entityManagerTypes = [
            'EntityManagerInterface',
            'EntityManager',
            EntityManagerInterface::class,
            EntityManager::class,
        ];

        assert(is_iterable($entityManagerTypes), '$entityManagerTypes must be iterable');

        foreach ($entityManagerTypes as $entityManagerType) {
            if (str_contains($typeName, $entityManagerType)) {
                return true;
            }
        }

        return false;
    }

    private function isEntityManagerPropertyName(string $propertyName): bool
    {
        $emPropertyNames = ['em', 'entityManager', 'manager'];

        return in_array($propertyName, $emPropertyNames, true);
    }

    private function createEntityManagerInConstructorIssue(string $entityClass, \ReflectionMethod $reflectionMethod): CodeQualityIssue
    {
        $shortClassName = $this->getShortClassName($entityClass);

        return new CodeQualityIssue([
            'title'       => 'EntityManager injected in entity constructor: ' . $shortClassName,
            'description' => sprintf(
                '🚨 CRITICAL ANTI-PATTERN: Entity "%s" has EntityManager injected in constructor.' . "

" .
                'This violates fundamental principles:' . "
" .
                '- Separation of Concerns (domain depends on infrastructure)' . "
" .
                '- Dependency Inversion (domain should not depend on ORM)' . "
" .
                '- Testability (impossible to unit test without database)' . "
" .
                '- Single Responsibility (entity manages both state and persistence)' . "

" .
                'Entities should be pure domain models with NO infrastructure dependencies.' . "
" .
                'Persistence logic belongs in Services, Command Handlers, or Repositories.',
                $shortClassName,
            ),
            'severity'   => 'critical',
            'suggestion' => $this->createEntityManagerSuggestion($entityClass, 'constructor'),
            'backtrace'  => [
                'file' => $reflectionMethod->getFileName(),
                'line' => $reflectionMethod->getStartLine(),
            ],
            'queries' => [],
        ]);
    }

    private function createEntityManagerPropertyIssue(string $entityClass, \ReflectionProperty $reflectionProperty): CodeQualityIssue
    {
        $shortClassName = $this->getShortClassName($entityClass);
        $propertyName   = $reflectionProperty->getName();

        return new CodeQualityIssue([
            'title'       => sprintf('EntityManager property in entity: %s::$%s', $shortClassName, $propertyName),
            'description' => sprintf(
                '🚨 CRITICAL ANTI-PATTERN: Entity "%s" has EntityManager as property "$%s".' . "

" .
                'This creates tight coupling between domain and infrastructure:' . "
" .
                '- Entity cannot be serialized (EntityManager is not serializable)' . "
" .
                '- Entity cannot be tested in isolation' . "
" .
                '- Violates Clean Architecture principles' . "
" .
                '- Makes entity dependent on ORM implementation' . "

" .
                'Move persistence operations to Service Layer.',
                $shortClassName,
                $propertyName,
            ),
            'severity'   => 'critical',
            'suggestion' => $this->createEntityManagerSuggestion($entityClass, 'property'),
            'backtrace'  => [
                'file' => $reflectionProperty->getDeclaringClass()->getFileName(),
                'line' => $reflectionProperty->getDeclaringClass()->getStartLine(),
            ],
            'queries' => [],
        ]);
    }

    private function createEntityManagerUsageIssue(string $entityClass, \ReflectionMethod $reflectionMethod): CodeQualityIssue
    {
        $shortClassName = $this->getShortClassName($entityClass);
        $methodName     = $reflectionMethod->getName();

        return new CodeQualityIssue([
            'title'       => sprintf('EntityManager usage in entity method: %s::%s()', $shortClassName, $methodName),
            'description' => sprintf(
                '🚨 CRITICAL ANTI-PATTERN: Entity "%s" uses EntityManager in method "%s()".' . "

" .
                'Detected persistence operations:' . "
" .
                '- flush(), persist(), or remove() called inside entity' . "

" .
                'Problems:' . "
" .
                '- Entity controls its own persistence (breaks SRP)' . "
" .
                '- Cannot be used without database' . "
" .
                '- Hidden side effects (unexpected database writes)' . "
" .
                '- Difficult to test and reason about' . "

" .
                'Solution: Move persistence to Application Services or Command Handlers.',
                $shortClassName,
                $methodName,
            ),
            'severity'   => 'critical',
            'suggestion' => $this->createEntityManagerSuggestion($entityClass, 'method', $methodName),
            'backtrace'  => [
                'file' => $reflectionMethod->getFileName(),
                'line' => $reflectionMethod->getStartLine(),
            ],
            'queries' => [],
        ]);
    }

    private function createEntityManagerSuggestion(string $entityClass, string $location, ?string $methodName = null): SuggestionInterface
    {
        $shortClassName = $this->getShortClassName($entityClass);

        $badCode = match ($location) {
            'constructor' => <<<PHP
                // BAD - Infrastructure dependency in entity
                class {$shortClassName} {
                    public function __construct(
                        private EntityManagerInterface \$em
                    ) {
                        \$this->items = new ArrayCollection();
                    }

                    public function addItem(Item \$item): void {
                        \$this->items->add(\$item);
                        \$this->em->persist(\$item); // Persistence in domain
                        \$this->em->flush();
                    }
                }
                PHP,
            'property' => <<<PHP
                // BAD - EntityManager as property
                class {$shortClassName} {
                    private EntityManagerInterface \$em;

                    public function setEntityManager(EntityManagerInterface \$em): void {
                        \$this->em = \$em;
                    }

                    public function save(): void {
                        \$this->em->persist(\$this);
                        \$this->em->flush();
                    }
                }
                PHP,
            'method' => <<<PHP
                // BAD - Persistence operations in entity method
                class {$shortClassName} {
                    public function {$methodName}(): void {
                        // ... business logic ...
                        \$this->em->flush(); // Hidden side effect
                    }
                }
                PHP,
            default => ''
        };

        $goodCode = <<<PHP
            //  GOOD - Pure domain model
            class {$shortClassName} {
                public function __construct() {
                    \$this->items = new ArrayCollection();
                }

                //  Pure business logic, no persistence
                public function addItem(Item \$item): void {
                    if (\$this->items->contains(\$item)) {
                        throw new DomainException('Item already exists');
                    }

                    \$this->items->add(\$item);
                    \$item->setOrder(\$this); // Maintain bidirectional relation
                }
            }

            //  GOOD - Persistence in Application Service
            class OrderService {
                public function __construct(
                    private EntityManagerInterface \$em,
                    private OrderRepository \$orderRepo
                ) {}

                public function addItemToOrder(int \$orderId, Item \$item): void {
                    \$order = \$this->orderRepo->find(\$orderId);

                    //  Domain logic in entity
                    \$order->addItem(\$item);

                    //  Persistence in service
                    \$this->em->persist(\$item);
                    \$this->em->flush();
                }
            }
            PHP;

        $benefits = [
            '**Testability**: Unit test entities without database',
            '**Portability**: Switch ORMs without changing entities',
            '**Clarity**: Clear separation between domain and infrastructure',
            '**Performance**: Batch operations in service layer',
            '**Transactions**: Control transaction boundaries explicitly',
        ];

        $suggestionMetadata = new SuggestionMetadata(
            type: SuggestionType::codeQuality(),
            severity: Severity::critical(),
            title: 'Remove EntityManager from Entity',
        );

        return $this->suggestionFactory->createFromTemplate(
            'entity_manager_in_entity',
            [
                'bad_code'    => $badCode,
                'good_code'   => $goodCode,
                'description' => 'Entities should be pure domain models. Move persistence logic to Services or Command Handlers.',
                'benefits'    => $benefits,
            ],
            $suggestionMetadata,
        );
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }
}
