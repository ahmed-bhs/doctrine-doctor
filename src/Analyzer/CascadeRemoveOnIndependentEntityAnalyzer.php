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
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\CodeQualityIssue;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Detects cascade="remove" on associations to independent entities.
 * This is CATASTROPHIC and can cause massive data loss.
 * NEVER use cascade="remove" on ManyToOne or ManyToMany.
 * Example of DISASTER:
 * class Order {
 *     @ManyToOne(targetEntity="Customer", cascade={"remove"})
 *     private Customer $customer;
 * }
 * $em->remove($order);
 * $em->flush();
 * //  DELETES THE CUSTOMER AND ALL THEIR OTHER ORDERS!
 */
class CascadeRemoveOnIndependentEntityAnalyzer implements AnalyzerInterface
{
    /**
     * Entity patterns that are typically independent.
     */
    private const INDEPENDENT_PATTERNS = [
        'User', 'Customer', 'Account', 'Member', 'Client',
        'Company', 'Organization', 'Team', 'Department',
        'Product', 'Category', 'Brand', 'Tag',
        'Author', 'Editor', 'Publisher',
        'Country', 'City', 'Region',
    ];

    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
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

                // Build reference count map
                $referenceCountMap = $this->buildReferenceCountMap($allMetadata);

                assert(is_iterable($allMetadata), '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    assert([] === $referenceCountMap || is_array($referenceCountMap));
                    $entityIssues = $this->analyzeEntity($metadata, $referenceCountMap);

                    assert(is_iterable($entityIssues), '$entityIssues must be iterable');

                    foreach ($entityIssues as $entityIssue) {
                        yield $entityIssue;
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Cascade Remove on Independent Entity Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects dangerous cascade="remove" on ManyToOne/ManyToMany which can cause massive data loss';
    }

    /**
     * @return array<string, int>
     */
    private function buildReferenceCountMap(array $allMetadata): array
    {
        /** @var array<string, int> $map */
        $map = [];

        assert(is_iterable($allMetadata), '$allMetadata must be iterable');

        foreach ($allMetadata as $metadata) {
            foreach ($metadata->getAssociationMappings() as $mapping) {
                $targetEntity = $mapping['targetEntity'] ?? null;

                if ($targetEntity) {
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
            $cascade      = MappingHelper::getArray($associationMapping, 'cascade') ?? [];
            $targetEntity = MappingHelper::getString($associationMapping, 'targetEntity') ?? null;

            // Check if has cascade remove
            if (!in_array('remove', $cascade, true) && !in_array('all', $cascade, true)) {
                continue;
            }

            $type = $this->getAssociationTypeConstant($associationMapping);

            // CRITICAL: cascade="remove" on ManyToOne
            if (ClassMetadata::MANY_TO_ONE === $type) {
                $issue    = $this->createCriticalIssue($entityClass, $fieldName, $associationMapping, $referenceCountMap);
                $issues[] = $issue;

                continue;
            }

            // HIGH: cascade="remove" on ManyToMany to independent entity
            if (null !== $targetEntity && ClassMetadata::MANY_TO_MANY === $type && $this->isIndependentEntity($targetEntity, $referenceCountMap)) {
                $issue    = $this->createHighIssue($entityClass, $fieldName, $associationMapping, $referenceCountMap);
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    private function isIndependentEntity(string $entityClass, array $referenceCountMap): bool
    {
        // Check name patterns
        foreach (self::INDEPENDENT_PATTERNS as $pattern) {
            if (str_contains($entityClass, $pattern)) {
                return true;
            }
        }

        // Check reference count
        $referenceCount = $referenceCountMap[$entityClass] ?? 0;

        return $referenceCount > 1;
    }

    private function createCriticalIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        array $referenceCountMap,
    ): CodeQualityIssue {
        $targetEntity   = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $cascade        = MappingHelper::getArray($mapping, 'cascade') ?? [];
        $referenceCount = $referenceCountMap[$targetEntity] ?? 0;

        $codeQualityIssue = new CodeQualityIssue([
            'entity'           => $entityClass,
            'field'            => $fieldName,
            'association_type' => 'ManyToOne',
            'target_entity'    => $targetEntity,
            'cascade'          => $cascade,
            'reference_count'  => $referenceCount,
        ]);

        $codeQualityIssue->setSeverity('critical');
        $codeQualityIssue->setTitle('🚨 CRITICAL: cascade="remove" on ManyToOne (Data Loss Risk)');

        $suggestion = $this->buildManyToOneSuggestion($entityClass, $fieldName, $mapping, $referenceCount);

        $message = DescriptionHighlighter::highlight(
            "Field {field} in entity {class} has {cascade} on {type} relation to {target}. This will DELETE the {target} when you delete the {shortClass}! This can cause MASSIVE data loss in production. REMOVE THIS IMMEDIATELY!\n\n{suggestion}",
            [
                'field' => $fieldName,
                'class' => $entityClass,
                'cascade' => '"remove"',
                'type' => 'ManyToOne',
                'target' => $targetEntity,
                'shortClass' => $this->getShortClassName($entityClass),
                'suggestion' => $suggestion,
            ],
        );
        $codeQualityIssue->setMessage($message);

        return $codeQualityIssue;
    }

    private function createHighIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        array $referenceCountMap,
    ): CodeQualityIssue {
        $targetEntity   = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $cascade        = MappingHelper::getArray($mapping, 'cascade') ?? [];
        $referenceCount = $referenceCountMap[$targetEntity] ?? 0;

        $codeQualityIssue = new CodeQualityIssue([
            'entity'           => $entityClass,
            'field'            => $fieldName,
            'association_type' => 'ManyToMany',
            'target_entity'    => $targetEntity,
            'cascade'          => $cascade,
            'reference_count'  => $referenceCount,
        ]);

        $codeQualityIssue->setSeverity('critical');
        $codeQualityIssue->setTitle('cascade="remove" on ManyToMany to Independent Entity');

        $suggestion = $this->buildManyToManySuggestion($entityClass, $fieldName, $mapping, $referenceCount);
        $message = DescriptionHighlighter::highlight(
            "Field {field} in entity {class} has {cascade} on {type} relation to independent entity {target}. This can delete shared entities. Remove {cascade}.\n\n{suggestion}",
            [
                'field' => $fieldName,
                'class' => $entityClass,
                'cascade' => '"remove"',
                'type' => 'ManyToMany',
                'target' => $targetEntity,
                'suggestion' => $suggestion,
            ],
        );
        $codeQualityIssue->setMessage($message);

        return $codeQualityIssue;
    }

    private function buildManyToOneSuggestion(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        int $referenceCount,
    ): string {
        $targetEntity    = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $shortClassName  = $this->getShortClassName($entityClass);
        $shortTargetName = $this->getShortClassName($targetEntity);

        $suggestions = [
            '🚨 CRITICAL: cascade="remove" on ManyToOne causes DATA LOSS',
            '',
            'Current (DANGEROUS):',
            sprintf('class %s { @ManyToOne(cascade={"remove"}) private %s $%s; }', $shortClassName, $shortTargetName, $fieldName),
            sprintf('→ Deleting a %s will DELETE the %s and break ALL other %ss!', $shortClassName, $shortTargetName, $shortClassName),
            '',
            'Solution: Remove cascade immediately',
            sprintf('class %s { @ManyToOne private %s $%s; }', $shortClassName, $shortTargetName, $fieldName),
            '',
            '📋 Rules:',
            '  NEVER: cascade="remove" on ManyToOne/ManyToMany',
            '  ONLY: cascade="remove" on OneToMany/OneToOne (composition)',
            '',
            sprintf(' %s is referenced by %d entities. Deleting it affects multiple parts!', $shortTargetName, $referenceCount),
        ];

        return implode("
", $suggestions);
    }

    private function buildManyToManySuggestion(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        int $referenceCount,
    ): string {
        $targetEntity    = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $shortClassName  = $this->getShortClassName($entityClass);
        $shortTargetName = $this->getShortClassName($targetEntity);

        $suggestions = [
            ' cascade="remove" on ManyToMany to independent entity',
            '',
            'Current:',
            sprintf('class %s { @ManyToMany(cascade={"remove"}) private Collection $%s; }', $shortClassName, $fieldName),
            sprintf('→ Deleting %s will DELETE ALL associated %ss (even if used elsewhere)', $shortClassName, $shortTargetName),
            '',
            ' Solution: Remove cascade',
            sprintf('class %s { @ManyToMany private Collection $%s; }', $shortClassName, $fieldName),
            '',
            sprintf('ManyToMany = shared relationships (Student↔Course, User↔Role, Product↔Tag)'),
            sprintf(' %s is referenced by %d entities', $shortTargetName, $referenceCount),
        ];

        return implode("
", $suggestions);
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
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
}
