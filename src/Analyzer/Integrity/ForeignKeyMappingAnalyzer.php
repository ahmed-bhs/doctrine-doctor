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
 * Detects foreign keys mapped as primitive types instead of object relations.
 * This is an anti-pattern that goes against Doctrine ORM best practices:
 * - Foreign keys should be mapped as ManyToOne/OneToOne relations
 * - Storing integer IDs defeats the purpose of using an ORM
 * - Makes code procedural instead of object-oriented
 * - Prevents lazy loading and relationship management
 * Example:
 * BAD:
 *   class Order {
 *       private int $userId;  // Foreign key as primitive
 *   }
 *  GOOD:
 *   class Order {
 *       private User $user;  // Proper object relation
 *   }
 */
class ForeignKeyMappingAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Common suffixes that indicate foreign key fields.
     */
    private const array FK_SUFFIXES = ['_id', 'Id', '_ID'];

    /**
     * Entity name patterns that are likely referenced entities.
     * More conservative list to avoid false positives.
     */
    private const array ENTITY_PATTERNS = [
        'user', 'customer', 'account', 'product', 'category',
        'company', 'organization', 'team', 'role', 'permission',
        'author', 'publisher', 'supplier', 'vendor', 'manufacturer',
        'country', 'state', 'province', 'city', 'region',
        'currency', 'language', 'locale', 'timezone',
    ];

    /**
     * Patterns that indicate a field is NOT a foreign key.
     * These are typically configuration, counting, or measurement fields.
     */
    private const array NON_FK_PATTERNS = [
        'number', 'amount', 'quantity', 'total', 'sum', 'count',
        'days', 'hours', 'minutes', 'seconds', 'duration', 'period',
        'age', 'year', 'month', 'week', 'date', 'time',
        'size', 'length', 'width', 'height', 'weight', 'volume',
        'price', 'cost', 'fee', 'rate', 'tax', 'discount',
        'status', 'level', 'priority', 'type', 'kind',
        'position', 'index', 'order', 'sort', 'rank', 'score',
        'version', 'revision', 'build', 'release',
        'limit', 'max', 'min', 'threshold', 'boundary',
        'flag', 'enabled', 'disabled', 'active', 'inactive',
        'configuration', 'setting', 'option', 'parameter',
        'expiration', 'expiry', 'timeout', 'delay',
        'code', 'hash', 'token', 'key', 'secret',
        'percentage', 'ratio', 'proportion', 'factor',
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

                Assert::isIterable($allMetadata, '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    $entityIssues = $this->analyzeEntity($metadata, $allMetadata);

                    Assert::isIterable($entityIssues, '$entityIssues must be iterable');

                    foreach ($entityIssues as $entityIssue) {
                        yield $entityIssue;
                    }
                }
            },
        );
    }

    /**
     * Analyze a single entity for foreign key mapping issues.
     */
    private function analyzeEntity(ClassMetadata $classMetadata, array $allMetadata): array
    {

        $issues      = [];
        $entityClass = $classMetadata->getName();

        $fieldMappings = $classMetadata->fieldMappings;

        Assert::isIterable($fieldMappings, '$fieldMappings must be iterable');

        foreach ($fieldMappings as $fieldName => $mapping) {
            $type = MappingHelper::getString($mapping, 'type');

            if (!in_array($type, ['integer', 'bigint', 'smallint'], true)) {
                continue;
            }

            if ($this->isForeignKeyField($fieldName) && !$this->hasProperRelation($classMetadata, $fieldName)) {
                $targetEntity = $this->guessTargetEntity($fieldName, $allMetadata);

                if (null !== $targetEntity && str_contains($targetEntity, '\\')) {
                    $issue = $this->createForeignKeyIssue(
                        $entityClass,
                        $fieldName,
                        $mapping,
                        $allMetadata,
                    );
                    $issues[] = $issue;
                }
            }
        }

        return $issues;
    }

    /**
     * Check if field name suggests it's a foreign key.
     * Uses improved heuristics to reduce false positives.
     */
    private function isForeignKeyField(string $fieldName): bool
    {
        $lowerFieldName = strtolower($fieldName);

        foreach (self::FK_SUFFIXES as $suffix) {
            if (str_ends_with($lowerFieldName, strtolower($suffix)) && 'id' !== $lowerFieldName) {
                return true;
            }
        }

        if ($this->isNonForeignKeyField($lowerFieldName)) {
            return false;
        }

        foreach (self::ENTITY_PATTERNS as $pattern) {
            if (str_contains($lowerFieldName, $pattern)) {
                if ($this->isSimpleEntityReference($lowerFieldName, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if field is explicitly NOT a foreign key based on patterns.
     */
    private function isNonForeignKeyField(string $lowerFieldName): bool
    {
        return array_any(self::NON_FK_PATTERNS, fn ($pattern) => $this->containsAsWholeWord($lowerFieldName, $pattern));
    }

    /**
     * Check if a pattern appears as a whole word or suffix in the field name.
     * This prevents false positives like "countryId" matching "count".
     */
    private function containsAsWholeWord(string $fieldName, string $pattern): bool
    {
        $patternLength = strlen($pattern);
        $fieldLength = strlen($fieldName);

        if (str_ends_with($fieldName, $pattern)) {
            return true;
        }

        if (str_starts_with($fieldName, $pattern)) {
            return true;
        }

        $pos = strpos($fieldName, $pattern);
        if (false === $pos) {
            return false;
        }

        $before = (0 === $pos) || !ctype_alpha($fieldName[$pos - 1]);
        $after = ($pos + $patternLength === $fieldLength) || !ctype_alpha($fieldName[$pos + $patternLength]);

        return $before && $after;
    }

    /**
     * Check if a field is a simple entity reference (not compound).
     * Examples:
     * - GOOD: userId, customerId, productId
     * - BAD: orderExpirationDays, userAge, productCount
     */
    private function isSimpleEntityReference(string $fieldName, string $entityPattern): bool
    {
        $patternParts = [
            $entityPattern,
            $entityPattern . '_id',
            $entityPattern . 'id',
            $entityPattern . '_uid',
            $entityPattern . 'uid',
        ];

        foreach ($patternParts as $pattern) {
            if ($fieldName === $pattern) {
                return true;
            }
        }

        $entityLength = strlen($entityPattern);
        $fieldLength = strlen($fieldName);

        if ($fieldLength > $entityLength + 6) { // Allow for _id suffix
            return false;
        }

        return true;
    }

    /**
     * Check if entity already has a proper object relation for this field.
     */
    private function hasProperRelation(ClassMetadata $classMetadata, string $fieldName): bool
    {
        $baseName = $fieldName;

        foreach (self::FK_SUFFIXES as $suffix) {
            if (str_ends_with($baseName, $suffix)) {
                $baseName = substr($baseName, 0, -strlen($suffix));
                break;
            }
        }

        $associations = $classMetadata->getAssociationNames();

        Assert::isIterable($associations, '$associations must be iterable');
        return array_any($associations, fn ($association) => strtolower((string) $association) === strtolower($baseName));
    }

    /**
     * Create issue for foreign key mapping anti-pattern.
     * @param array<string, mixed>|object $mapping
     */
    private function createForeignKeyIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        array $allMetadata,
    ): IntegrityIssue {
        $targetEntity = $this->guessTargetEntity($fieldName, $allMetadata);

        $type = MappingHelper::getString($mapping, 'type');

        $backtrace = $this->createEntityFieldBacktrace($entityClass, $fieldName);

        $codeQualityIssue = new IntegrityIssue([
            'entity'        => $entityClass,
            'field'         => $fieldName,
            'type'          => $type,
            'target_entity' => $targetEntity,
            'backtrace'     => $backtrace,
        ]);

        $codeQualityIssue->setSeverity('warning');
        $codeQualityIssue->setTitle('Foreign Key Mapped as Primitive Type');

        $message = DescriptionHighlighter::highlight(
            "Field {field} in entity {class} appears to be a foreign key but is mapped as a primitive type {type}. This is an anti-pattern in Doctrine ORM.",
            [
                'field' => $fieldName,
                'class' => $entityClass,
                'type' => $type,
            ],
        );
        $codeQualityIssue->setMessage($message);
        $codeQualityIssue->setSuggestion($this->createSuggestionInterface($entityClass, $fieldName, $targetEntity));

        return $codeQualityIssue;
    }

    /**
     * Try to guess the target entity from field name.
     */
    private function guessTargetEntity(string $fieldName, array $allMetadata): ?string
    {
        $baseName = $fieldName;

        foreach (self::FK_SUFFIXES as $suffix) {
            if (str_ends_with($baseName, $suffix)) {
                $baseName = substr($baseName, 0, -strlen($suffix));
                break;
            }
        }

        $baseNameLower = strtolower($baseName);

        Assert::isIterable($allMetadata, '$allMetadata must be iterable');

        foreach ($allMetadata as $metadata) {
            $className = $metadata->getName();
            $shortName = strtolower($this->getShortClassName($className));

            if ($shortName === $baseNameLower) {
                return $className;
            }
        }

        return ucfirst($baseName);
    }

    /**
     * Get short class name (without namespace).
     */
    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }

    /**
     * Create suggestion interface for fixing the issue.
     */
    private function createSuggestionInterface(string $entityClass, string $fieldName, ?string $targetEntity): SuggestionInterface
    {
        $baseName = $fieldName;

        foreach (self::FK_SUFFIXES as $suffix) {
            if (str_ends_with($baseName, $suffix)) {
                $baseName = substr($baseName, 0, -strlen($suffix));
                break;
            }
        }

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/foreign_key_primitive',
            context: [
                'entity_class'     => $this->getShortClassName($entityClass),
                'field_name'       => $fieldName,
                'target_entity'    => $targetEntity ?? 'Unknown',
                'association_type' => 'ManyToOne',
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::warning(),
                title: 'Foreign Key Mapped as Primitive Type',
                tags: ['code-quality', 'orm', 'anti-pattern'],
            ),
        );
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
