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
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;
use Webmozart\Assert\Assert;

/**
 * Detects cascade="persist" on associations to independent entities.
 * This is a common mistake that leads to duplicate records.
 * When you use cascade="persist" on a Customer, Product, etc.,
 * you risk creating duplicates instead of using existing records.
 * Example:
 * class Order {
 *     @ManyToOne(targetEntity="Customer", cascade={"persist"})
 *     private Customer $customer;
 * }
 * $customer = new Customer();  // Should load existing, not create new!
 * $order->setCustomer($customer);
 * $em->persist($order);  // Creates DUPLICATE customer
 */
class CascadePersistOnIndependentEntityAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Entity patterns that are HIGHLY independent (critical issue).
     * These should NEVER have cascade=persist from other entities.
     */
    private const array CRITICAL_INDEPENDENT_PATTERNS = [
        'User', 'Customer', 'Account', 'Member', 'Client', 'Subscriber',
        'Company', 'Organization', 'Role', 'Permission',
    ];

    /**
     * Entity patterns that are typically independent (warning level).
     * Referenced by multiple entities but might have legitimate cascade cases.
     */
    private const array INDEPENDENT_PATTERNS = [
        // Organization
        'Team', 'Department', 'Group',
        // Catalog
        'Product', 'Category', 'Brand', 'Tag', 'Label',
        // Content
        'Author', 'Editor', 'Publisher', 'Writer',
        // Location
        'Country', 'City', 'Region', 'Address',
        // Other common independent entities
        'Status', 'Type', 'Currency',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactory $suggestionFactory,
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
                $allMetadata          = $classMetadataFactory->getAllMetadata();

                // Build reference count map (how many entities reference each entity)
                $referenceCountMap = $this->buildReferenceCountMap($allMetadata);

                Assert::isIterable($allMetadata, '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    $entityIssues = $this->analyzeEntity($metadata, $referenceCountMap);

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
        return 'Cascade Persist on Independent Entity Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects cascade="persist" on independent entities which can lead to duplicate records';
    }

    /**
     * Count how many entities reference each entity
     * (entities referenced by multiple others are likely independent).
     */
    private function buildReferenceCountMap(array $allMetadata): array
    {

        $map = [];

        Assert::isIterable($allMetadata, '$allMetadata must be iterable');

        foreach ($allMetadata as $metadata) {
            foreach ($metadata->getAssociationMappings() as $mapping) {
                $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? null;

                if (null !== $targetEntity) {
                    $map[$targetEntity] = ($map[$targetEntity] ?? 0) + 1;
                }
            }
        }

        return $map;
    }

    /**
     * @param ClassMetadata<object>    $classMetadata
     * @param array<string, float|int> $referenceCountMap
     * @return array<\AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function analyzeEntity(ClassMetadata $classMetadata, array $referenceCountMap): array
    {

        $issues      = [];
        $entityClass = $classMetadata->getName();

        foreach ($classMetadata->getAssociationMappings() as $fieldName => $associationMapping) {
            $cascade      = $associationMapping['cascade'] ?? [];
            $targetEntity = $associationMapping['targetEntity'] ?? null;

            // Skip if no cascade persist
            if (!in_array('persist', $cascade, true) && !in_array('all', $cascade, true)) {
                continue;
            }

            // Only check ManyToOne and ManyToMany (associations to independent entities)
            $type = $this->getAssociationTypeConstant($associationMapping);

            if (!in_array($type, [ClassMetadata::MANY_TO_ONE, ClassMetadata::MANY_TO_MANY], true)) {
                continue;
            }

            // Check if target is an independent entity
            if ($this->isIndependentEntity($targetEntity, $referenceCountMap)) {
                $issue    = $this->createIssue($entityClass, $fieldName, $associationMapping, $referenceCountMap);
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * Determine if an entity is independent based on:
     * 1. Name patterns (User, Customer, Product, etc.)
     * 2. Reference count (referenced by multiple entities)
     */
    private function isIndependentEntity(string $entityClass, array $referenceCountMap): bool
    {
        // Check critical patterns
        foreach (self::CRITICAL_INDEPENDENT_PATTERNS as $pattern) {
            if (str_contains($entityClass, $pattern)) {
                return true;
            }
        }

        // Check name patterns
        foreach (self::INDEPENDENT_PATTERNS as $pattern) {
            if (str_contains($entityClass, $pattern)) {
                return true;
            }
        }

        // Check if referenced by multiple entities (3+ references = likely independent)
        $referenceCount = $referenceCountMap[$entityClass] ?? 0;

        return $referenceCount >= 3;
    }

    /**
     * Determine if an entity is HIGHLY independent (User, Customer, etc.).
     */
    private function isCriticallyIndependentEntity(string $entityClass): bool
    {
        return array_any(self::CRITICAL_INDEPENDENT_PATTERNS, fn ($pattern) => str_contains($entityClass, (string) $pattern));
    }

    private function createIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        array $referenceCountMap,
    ): IntegrityIssue {
        $targetEntity   = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $cascade        = MappingHelper::getArray($mapping, 'cascade') ?? [];
        $type           = $this->getAssociationType($mapping);
        $referenceCount = $referenceCountMap[$targetEntity] ?? 0;

        // Create synthetic backtrace from entity reflection
        $backtrace = $this->createEntityFieldBacktrace($entityClass, $fieldName);

        $codeQualityIssue = new IntegrityIssue([
            'entity'           => $entityClass,
            'field'            => $fieldName,
            'association_type' => $type,
            'target_entity'    => $targetEntity,
            'cascade'          => $cascade,
            'reference_count'  => $referenceCount,
            'backtrace'        => $backtrace,
        ]);

        // Determine severity based on entity type and reference count
        $severity = $this->determineSeverity($targetEntity, $referenceCount);

        $codeQualityIssue->setSeverity($severity);
        $codeQualityIssue->setTitle('cascade="persist" on Independent Entity (Risk of Duplicates)');

        $message = DescriptionHighlighter::highlight(
            "Field {field} in entity {class} has {cascade} on independent entity {target}. This can lead to duplicate records. Independent entities should be loaded from the database, not created.",
            [
                'field' => $fieldName,
                'class' => $entityClass,
                'cascade' => '"persist"',
                'target' => $targetEntity,
            ],
        );
        $codeQualityIssue->setMessage($message);
        $codeQualityIssue->setSuggestion($this->createCascadePersistSuggestion($entityClass, $fieldName, $mapping, $referenceCount));

        return $codeQualityIssue;
    }

    /**
     * Determine severity based on entity type and reference count.
     * - critical: User, Customer, Account (obvious duplicates risk)
     * - high: Entities with 5+ references
     * - warning: Entities with 3-4 references
     * - info: Entities with 1-2 references (might be composition, not independent)
     */
    private function determineSeverity(string $targetEntity, int $referenceCount): string
    {
        // Critical: User, Customer, etc.
        if ($this->isCriticallyIndependentEntity($targetEntity)) {
            return 'critical';
        }

        // High: Heavily referenced entities
        if ($referenceCount >= 5) {
            return 'warning';
        }

        // Warning: Moderately referenced
        if ($referenceCount >= 3) {
            return 'warning';
        }

        // Info: Low reference count (might be composition)
        return 'info';
    }

    private function getAssociationType(array|object $mapping): string
    {
        $type = $this->getAssociationTypeConstant($mapping);

        return match ($type) {
            ClassMetadata::MANY_TO_ONE  => 'ManyToOne',
            ClassMetadata::MANY_TO_MANY => 'ManyToMany',
            default                     => 'Unknown',
        };
    }

    /**
     * Get association type constant in a version-agnostic way.
     * Doctrine ORM 2.x uses 'type' field, 3.x/4.x uses specific mapping classes.
     */
    private function getAssociationTypeConstant(array|object $mapping): int
    {
        // Try to get type from array (Doctrine ORM 2.x)
        $type = MappingHelper::getInt($mapping, 'type');
        if (null !== $type) {
            return $type;
        }

        // Doctrine ORM 3.x/4.x: determine from class name
        if (is_object($mapping)) {
            $className = $mapping::class;

            if (str_contains($className, 'ManyToOne')) {
                return (int) ClassMetadata::MANY_TO_ONE;
            }

            if (str_contains($className, 'OneToMany')) {
                return (int) ClassMetadata::ONE_TO_MANY;
            }

            if (str_contains($className, 'ManyToMany')) {
                return (int) ClassMetadata::MANY_TO_MANY;
            }

            if (str_contains($className, 'OneToOne')) {
                return (int) ClassMetadata::ONE_TO_ONE;
            }
        }

        return 0; // Unknown
    }

    private function createCascadePersistSuggestion(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        int $referenceCount,
    ): SuggestionInterface {
        $targetEntity    = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $shortClassName  = $this->getShortClassName($entityClass);
        $shortTargetName = $this->getShortClassName($targetEntity);
        $type            = $this->getAssociationType($mapping);

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/cascade_persist_independent',
            context: [
                'entity_class'     => $shortClassName,
                'field_name'       => $fieldName,
                'target_entity'    => $shortTargetName,
                'reference_count'  => $referenceCount,
                'association_type' => $type,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::critical(),
                title: 'cascade="persist" on Independent Entity (Risk of Duplicates)',
                tags: ['critical', 'cascade', 'duplicates', 'anti-pattern'],
            ),
        );
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }

    /**
     * Create synthetic backtrace pointing to entity field.
     * @return array<int, array<string, mixed>>|null
     */
    private function createEntityFieldBacktrace(string $entityClass, string $fieldName): ?array
    {
        try {
            Assert::classExists($entityClass);
            $reflectionClass = new ReflectionClass($entityClass);
            $fileName        = $reflectionClass->getFileName();

            if (false === $fileName) {
                return null;
            }

            // Try to find the property line
            $lineNumber = $reflectionClass->getStartLine();

            if ($reflectionClass->hasProperty($fieldName)) {
                $reflectionProperty = $reflectionClass->getProperty($fieldName);
                $propertyLine       = $reflectionProperty->getDeclaringClass()->getStartLine();

                if (false !== $propertyLine) {
                    $lineNumber = $propertyLine;
                }
            }

            return [
                [
                    'file'     => $fileName,
                    'line'     => $lineNumber ?: 1,
                    'class'    => $entityClass,
                    'function' => '$' . $fieldName,
                    'type'     => '::',
                ],
            ];
        } catch (\Exception) {
            return null;
        }
    }
}
