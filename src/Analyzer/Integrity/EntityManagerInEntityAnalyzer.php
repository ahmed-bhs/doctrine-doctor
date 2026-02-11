<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\PhpCodeParser;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Detects EntityManager injection in entity classes.
 *
 * Example:
 * ```php
 * class Order {
 *     public function __construct(private EntityManagerInterface $em,
        private readonly ?LoggerInterface $logger = null) {}
 *     public function addItem(OrderItem $item) {
 *         $this->items->add($item);
 *         $this->em->persist($item);
 *         $this->em->flush();
 *     }
 * }
 * ```
 * Entities should not handle persistence operations.
 */
class EntityManagerInEntityAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    private readonly PhpCodeParser $phpCodeParser;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly ?LoggerInterface $logger = null,
        ?PhpCodeParser $phpCodeParser = null,
    ) {
        $this->phpCodeParser = $phpCodeParser ?? new PhpCodeParser($logger);
    }

    /**
     * @param QueryDataCollection $queryDataCollection - Not used, this analyzer focuses on entity metadata
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
     * @return array<IntegrityIssue>
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

        Assert::isIterable($emProperties, '$emProperties must be iterable');

        foreach ($emProperties as $emProperty) {
            $issue = $this->createEntityManagerPropertyIssue($entityClass, $emProperty);
            if ($issue instanceof IntegrityIssue) {
                $issues[] = $issue;
            }
        }

        // Check 3: EntityManager usage in methods (flush, persist, etc.)
        $methodsUsingEM = $this->findMethodsUsingEntityManager($reflectionClass);

        Assert::isIterable($methodsUsingEM, '$methodsUsingEM must be iterable');

        foreach ($methodsUsingEM as $methodUsing) {
            $issue = $this->createEntityManagerUsageIssue($entityClass, $methodUsing);
            if ($issue instanceof IntegrityIssue) {
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

            // Use PhpCodeParser instead of fragile regex
            // This provides robust AST-based detection that handles:
            // - $this->em->flush()
            // - $this->entityManager->persist()
            // - $em->remove()
            // - Ignores comments automatically (no false positives)
            // - Type-safe detection with proper scope analysis

            $emMethods = [
                '$*->flush',       // Matches: $em->flush(), $this->em->flush(), etc.
                '$*->persist',     // Matches: $em->persist(), $this->entityManager->persist(), etc.
                '$*->remove',      // Matches: $em->remove(), $this->em->remove(), etc.
            ];

            foreach ($emMethods as $pattern) {
                if ($this->phpCodeParser->hasMethodCall($reflectionMethod, $pattern)) {
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

        Assert::isIterable($entityManagerTypes, '$entityManagerTypes must be iterable');
        return array_any($entityManagerTypes, fn ($entityManagerType) => str_contains($typeName, (string) $entityManagerType));
    }

    private function isEntityManagerPropertyName(string $propertyName): bool
    {
        $emPropertyNames = ['em', 'entityManager', 'manager'];

        return in_array($propertyName, $emPropertyNames, true);
    }

    private function createEntityManagerInConstructorIssue(string $entityClass, \ReflectionMethod $reflectionMethod): IntegrityIssue
    {
        $shortClassName = $this->getShortClassName($entityClass);

        return new IntegrityIssue([
            'title'       => 'EntityManager injected in entity constructor: ' . $shortClassName,
            'description' => sprintf(
                'Entity "%s" has EntityManager injected in constructor.' . "

" .
                'Issues:' . "
" .
                '- Couples domain to infrastructure' . "
" .
                '- Breaks dependency inversion' . "
" .
                '- Difficult to test without database' . "
" .
                '- Entity handles both state and persistence' . "

" .
                'Move persistence logic to Services or Repositories.',
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

    private function createEntityManagerPropertyIssue(string $entityClass, \ReflectionProperty $reflectionProperty): IntegrityIssue
    {
        $shortClassName = $this->getShortClassName($entityClass);
        $propertyName   = $reflectionProperty->getName();

        return new IntegrityIssue([
            'title'       => sprintf('EntityManager property in entity: %s::$%s', $shortClassName, $propertyName),
            'description' => sprintf(
                'Entity "%s" has EntityManager as property "$%s".' . "

" .
                'Issues:' . "
" .
                '- Entity cannot be serialized' . "
" .
                '- Cannot be tested in isolation' . "
" .
                '- Tight coupling to ORM' . "

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

    private function createEntityManagerUsageIssue(string $entityClass, \ReflectionMethod $reflectionMethod): IntegrityIssue
    {
        $shortClassName = $this->getShortClassName($entityClass);
        $methodName     = $reflectionMethod->getName();

        return new IntegrityIssue([
            'title'       => sprintf('EntityManager usage in entity method: %s::%s()', $shortClassName, $methodName),
            'description' => sprintf(
                'Entity "%s" uses EntityManager in method "%s()".' . "

" .
                'Detected: flush(), persist(), or remove() calls' . "

" .
                'Issues:' . "
" .
                '- Entity controls its own persistence' . "
" .
                '- Requires database to use' . "
" .
                '- Hidden side effects' . "
" .
                '- Difficult to test' . "

" .
                'Move persistence to Services or Command Handlers.',
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
            type: SuggestionType::integrity(),
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
