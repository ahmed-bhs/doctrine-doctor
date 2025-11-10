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
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\CodeQualityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Analyzes cascaconfiguration on entity associations.
 * Detects:
 * - Overuse of cascade={"all"} which can cause accidental data loss
 * - Missing cascade where it makes sense (composition relationships)
 * - Dangerous cascade={"remove"} on independent entities.
 */
class CascadeConfigurationAnalyzer implements AnalyzerInterface
{
    // Entities that should typically have cascade persist/remove (composition)
    private const TYPICAL_COMPOSED_PATTERNS = [
        'Item', 'Line', 'Detail', 'Entry', 'Component', 'Part',
    ];

    // Entities that should NOT have cascade remove (independent entities)
    private const INDEPENDENT_ENTITY_PATTERNS = [
        'User', 'Customer', 'Account', 'Company', 'Organization',
    ];

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
                    $this->logger?->error('CascadeConfigurationAnalyzer failed', [
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                        'file' => $throwable->getFile(),
                        'line' => $throwable->getLine(),
                    ]);
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Cascade Configuration Analyzer';
    }

    public function getDescription(): string
    {
        return 'Analyzes cascaconfiguration on associations to detect overuse of cascade="all", dangerous cascade="remove", and missing cascade on composition relationships';
    }

    /**
     * @return array<CodeQualityIssue>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {

        $issues      = [];
        $entityClass = $classMetadata->getName();

        foreach ($classMetadata->getAssociationMappings() as $fieldName => $associationMapping) {
            // Check for cascade="all" abuse
            if ($this->hasCascadeAll($associationMapping)) {
                $issue    = $this->checkCascadeAll($entityClass, $fieldName, $associationMapping);
                $issues[] = $issue;
            }

            // Check for dangerous cascade remove
            if ($this->hasCascadeRemove($associationMapping)) {
                $issue = $this->checkDangerousCascadeRemove($entityClass, $fieldName, $associationMapping);

                if ($issue instanceof CodeQualityIssue) {
                    $issues[] = $issue;
                }
            }

            // Check for missing cascade on composition relationships
            if ($this->isCompositionRelationship($associationMapping)) {
                $issue = $this->checkMissingCascade($entityClass, $fieldName, $associationMapping);

                if ($issue instanceof CodeQualityIssue) {
                    $issues[] = $issue;
                }
            }
        }

        return $issues;
    }

    private function hasCascadeAll(array|object $mapping): bool
    {
        $cascade = MappingHelper::getArray($mapping, 'cascade') ?? [];

        return in_array('all', $cascade, true);
    }

    private function hasCascadeRemove(array|object $mapping): bool
    {
        $cascade = MappingHelper::getArray($mapping, 'cascade') ?? [];

        return in_array('all', $cascade, true) || in_array('remove', $cascade, true);
    }

    private function isCompositionRelationship(array|object $mapping): bool
    {
        // Composition typically means OneToMany or OneToOne with certain naming patterns
        $type = $this->getAssociationTypeConstant($mapping);

        if (ClassMetadata::ONE_TO_MANY !== $type && ClassMetadata::ONE_TO_ONE !== $type) {
            return false;
        }

        $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? '';

        foreach (self::TYPICAL_COMPOSED_PATTERNS as $pattern) {
            if (false !== stripos((string) $targetEntity, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isIndependentEntity(string $entityClass): bool
    {
        foreach (self::INDEPENDENT_ENTITY_PATTERNS as $pattern) {
            if (false !== stripos($entityClass, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function checkCascadeAll(string $entityClass, string $fieldName, array|object $mapping): CodeQualityIssue
    {
        $shortClassName = $this->getShortClassName($entityClass);
        $targetEntity   = $this->getShortClassName(MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown');

        // Create synthetic backtrace
        $backtrace = $this->createEntityFieldBacktrace($entityClass, $fieldName);

        // Check if target is an independent entity
        if ($this->isIndependentEntity(MappingHelper::getString($mapping, 'targetEntity') ?? '')) {
            return new CodeQualityIssue([
                'title'       => sprintf('Dangerous cascade="all" in %s::$%s', $shortClassName, $fieldName),
                'description' => sprintf(
                    'Entity "%s" has cascade="all" on property "$%s" (relation to %s). ' .
                    'Using cascade="all" on independent entities like %s is dangerous because ' .
                    'removing the parent entity will CASCADE DELETE all related entities. ' .
                    'This can cause massive accidental data loss in production. ' .
                    'Consider using more specific cascade options like ["persist"] only.',
                    $shortClassName,
                    $fieldName,
                    $targetEntity,
                    $targetEntity,
                ),
                'severity'   => 'critical',
                'suggestion' => $this->createCascadeAllSuggestion($entityClass, $fieldName, $mapping),
                'backtrace'  => $backtrace,
                'queries'    => [],
            ]);
        }

        return new CodeQualityIssue([
            'title'       => sprintf('Overuse of cascade="all" in %s::$%s', $shortClassName, $fieldName),
            'description' => sprintf(
                'Entity "%s" uses cascade="all" on property "$%s" (relation to %s). ' .
                'While this might work, cascade="all" includes persist, remove, merge, detach, and refresh. ' .
                'Be explicit about which cascade operations you actually need. ' .
                'This prevents unexpected behavior and makes the code more maintainable.',
                $shortClassName,
                $fieldName,
                $targetEntity,
            ),
            'severity'   => 'warning',
            'suggestion' => $this->createCascadeAllSuggestion($entityClass, $fieldName, $mapping),
            'backtrace'  => $backtrace,
            'queries'    => [],
        ]);
    }

    private function checkDangerousCascadeRemove(string $entityClass, string $fieldName, array|object $mapping): ?CodeQualityIssue
    {
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? '';

        // Only flag if target is an independent entity
        if (!$this->isIndependentEntity($targetEntity)) {
            return null;
        }

        $shortClassName  = $this->getShortClassName($entityClass);
        $targetShortName = $this->getShortClassName($targetEntity);

        // Create synthetic backtrace
        $backtrace = $this->createEntityFieldBacktrace($entityClass, $fieldName);

        return new CodeQualityIssue([
            'title'       => 'Dangerous cascade remove on independent entity ' . $targetShortName,
            'description' => sprintf(
                'Entity "%s" has cascade remove on property "$%s" pointing to independent entity "%s". ' .
                'Deleting a %s will automatically delete all associated %s entities. ' .
                'This is usually NOT what you want for independent entities. ' .
                'Consider removing cascade="remove" and handling deletion separately.',
                $shortClassName,
                $fieldName,
                $targetShortName,
                $shortClassName,
                $targetShortName,
            ),
            'severity'   => 'critical',
            'suggestion' => $this->createRemoveCascadeRemoveSuggestion($entityClass, $fieldName, $mapping),
            'backtrace'  => $backtrace,
            'queries'    => [],
        ]);
    }

    private function checkMissingCascade(string $entityClass, string $fieldName, array|object $mapping): ?CodeQualityIssue
    {
        $cascade = MappingHelper::getArray($mapping, 'cascade') ?? [];

        // If no cascade at all, suggest adding it for composition
        if ([] === $cascade) {
            $shortClassName = $this->getShortClassName($entityClass);
            $targetEntity   = $this->getShortClassName(MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown');

            // Create synthetic backtrace
            $backtrace = $this->createEntityFieldBacktrace($entityClass, $fieldName);

            return new CodeQualityIssue([
                'title'       => sprintf('Missing cascade on composition relationship %s::$%s', $shortClassName, $fieldName),
                'description' => sprintf(
                    'Entity "%s" has a composition relationship with "%s" (property "$%s") but no cascaconfiguration. ' .
                    'Composition relationships typically need cascade=["persist", "remove"] so that child entities ' .
                    'are automatically persisted and removed with the parent. Without cascade, you must manually ' .
                    'persist each child entity, which is error-prone.',
                    $shortClassName,
                    $targetEntity,
                    $fieldName,
                ),
                'severity'   => 'warning',
                'suggestion' => $this->createAddCascadeSuggestion($entityClass, $fieldName, $mapping),
                'backtrace'  => $backtrace,
                'queries'    => [],
            ]);
        }

        return null;
    }

    private function createCascadeAllSuggestion(string $entityClass, string $fieldName, array|object $mapping): SuggestionInterface
    {
        $shortClassName = $this->getShortClassName($entityClass);
        $isComposition  = $this->isCompositionRelationship($mapping);

        $recommendedCascade = $isComposition ? '["persist", "remove"]' : '["persist"]';

        $code     = "// In {$shortClassName} class:

";
        $mappedBy = MappingHelper::getString($mapping, 'mappedBy') ?? 'parent';

        if ($this->isAttribute()) {
            $code .= "#[OneToMany(
";
            $code .= "    targetEntity: {$this->getShortClassName(MappingHelper::getString($mapping, 'targetEntity') ?? '')},
";
            $code .= "    mappedBy: '{$mappedBy}',
";
            $code .= "    cascade: {$recommendedCascade}  // Changed from ['all']
";
            $code .= ")]
";
        } else {
            $code .= "/**
";
            $code .= " * @ORM\OneToMany(
";
            $code .= " *     targetEntity=\"{MappingHelper::getString({$mapping}, 'targetEntity')}\",
";
            $code .= " *     mappedBy=\"{$mappedBy}\",
";
            $code .= " *     cascade={\"persist\", \"remove\"}  // Changed from {\"all\"}
";
            $code .= " * )
";
            $code .= " */
";
        }

        $code .= sprintf('private Collection $%s;', $fieldName);

        return $this->suggestionFactory->createCodeSuggestion(
            description: $isComposition
                ? 'Replace cascade="all" with explicit cascade options for composition'
                : 'Replace cascade="all" with cascade="persist" only',
            code: $code,
            filePath: $entityClass,
        );
    }

    private function createRemoveCascadeRemoveSuggestion(string $entityClass, string $fieldName, array|object $mapping): SuggestionInterface
    {
        $shortClassName = $this->getShortClassName($entityClass);
        $mappedBy       = MappingHelper::getString($mapping, 'mappedBy') ?? 'parent';

        $code = "// In {$shortClassName} class:

";
        $code .= "// Remove cascade remove to prevent accidental deletion
";

        if ($this->isAttribute()) {
            $code .= "#[OneToMany(
";
            $code .= "    targetEntity: {$this->getShortClassName(MappingHelper::getString($mapping, 'targetEntity') ?? '')},
";
            $code .= "    mappedBy: '{$mappedBy}'
";
            $code .= "    // No cascade - entities are independent
";
            $code .= ")]
";
        } else {
            $code .= "/**
";
            $code .= " * @ORM\OneToMany(
";
            $code .= " *     targetEntity=\"{MappingHelper::getString({$mapping}, 'targetEntity')}\",
";
            $code .= " *     mappedBy=\"{$mappedBy}\"
";
            $code .= " * )
";
            $code .= " * No cascade - entities are independent
";
            $code .= " */
";
        }

        $code .= sprintf('private Collection $%s;', $fieldName);

        return $this->suggestionFactory->createCodeSuggestion(
            description: 'Remove cascade remove to prevent accidental data loss',
            code: $code,
            filePath: $entityClass,
        );
    }

    private function createAddCascadeSuggestion(string $entityClass, string $fieldName, array|object $mapping): SuggestionInterface
    {
        $shortClassName = $this->getShortClassName($entityClass);
        $mappedBy       = MappingHelper::getString($mapping, 'mappedBy') ?? 'parent';

        $code = "// In {$shortClassName} class:

";

        if ($this->isAttribute()) {
            $code .= "#[OneToMany(
";
            $code .= "    targetEntity: {$this->getShortClassName(MappingHelper::getString($mapping, 'targetEntity') ?? '')},
";
            $code .= "    mappedBy: '{$mappedBy}',
";
            $code .= "    cascade: ['persist', 'remove']  // Add cascade for composition
";
            $code .= ")]
";
        } else {
            $code .= "/**
";
            $code .= " * @ORM\OneToMany(
";
            $code .= " *     targetEntity=\"{MappingHelper::getString({$mapping}, 'targetEntity')}\",
";
            $code .= " *     mappedBy=\"{$mappedBy}\",
";
            $code .= " *     cascade={\"persist\", \"remove\"}  // Add cascade for composition
";
            $code .= " * )
";
            $code .= " */
";
        }

        $code .= sprintf('private Collection $%s;', $fieldName);

        return $this->suggestionFactory->createCodeSuggestion(
            description: 'Add cascade persist and remove for composition relationship',
            code: $code,
            filePath: $entityClass,
        );
    }

    private function isAttribute(): bool
    {
        // Simple heuristic: check if we're using PHP 8 attributes vs annotations
        // In practice, this would need more sophisticated detection
        return PHP_VERSION_ID >= 80000;
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
            assert(class_exists($entityClass));
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
