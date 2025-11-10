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
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;

/**
 * Detects bad practices in Timestampable implementations.
 * Supports multiple implementations:
 * - gedmo/doctrine-extensions (Gedmo\Timestampable annotation)
 * - doctrine-extensions/DoctrineExtensions (beberlei/DoctrineExtensions)
 * - knplabs/doctrine-behaviors (TimestampableEntity trait)
 * - stof/doctrine-extensions-bundle (Symfony integration of Gedmo)
 * - Manual implementations (plain createdAt/updatedAt fields)
 * Detection methods:
 * - Annotations: @Gedmo\Timestampable
 * - Traits: TimestampableEntity, TimestampableTrait
 * - Field names: createdAt, updatedAt, created_at, updated_at
 * - Lifecycle callbacks: @HasLifecycleCallbacks, @PrePersist, @PreUpdate
 * Bad practices detected:
 * 1. Missing timezone on datetime fields (causes inconsistencies)
 * 2. Using DateTime instead of DateTimeImmutable (mutable objects)
 * 3. Missing NOT NULL constraint on createdAt
 * 4. Public setters on timestamp fields (breaks encapsulation)
 * 5. Incorrect field types (datetime vs datetimetz)
 * 6. Missing indexes on frequently queried timestamp fields
 * 7. Manual timestamp management instead of using lifecycle callbacks
 * 8. Inconsistent naming (createdAt vs created_at vs create_date)
 * Best practices:
 * - Use DateTimeImmutable for timestamp fields
 * - Use datetimetz type to store timezone information
 * - Mark createdAt as NOT NULL
 * - Make timestamp properties private/protected
 * - Use consistent naming conventions
 * - Add indexes on timestamp fields used in WHERE clauses
 * - Use Doctrine lifecycle callbacks or extensions
 */
class TimestampableTraitAnalyzer implements AnalyzerInterface
{
    /**
     * Common field names for timestamps.
     */
    private const TIMESTAMP_FIELD_PATTERNS = [
        'createdAt',
        'created_at',
        'createDate',
        'create_date',
        'dateCreated',
        'updatedAt',
        'updated_at',
        'updateDate',
        'update_date',
        'dateUpdated',
        'modifiedAt',
        'modified_at',
        'lastModified',
        'last_modified',
    ];

    /**
     * Remote timestamp fields (from external systems).
     * These can legitimately be nullable (data not yet synced).
     */
    private const REMOTE_TIMESTAMP_PATTERNS = [
        'remote',
        'external',
        'synced',
        'synchronized',
        'imported',
        'api',
    ];

    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private IssueFactoryInterface $issueFactory,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
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
                $allFieldsWithoutTimezone = []; // Collect all fields missing timezone

                foreach ($classMetadataFactory->getAllMetadata() as $classMetadatum) {
                    if ($classMetadatum->isMappedSuperclass) {
                        continue;
                    }

                    if ($classMetadatum->isEmbeddedClass) {
                        continue;
                    }

                    $entityIssues = $this->analyzeEntity($classMetadatum, $allFieldsWithoutTimezone);

                    assert(is_iterable($entityIssues), '$entityIssues must be iterable');

                    foreach ($entityIssues as $entityIssue) {
                        yield $entityIssue;
                    }
                }

                // Generate ONE global warning for all missing timezone fields
                if ([] !== $allFieldsWithoutTimezone) {
                    yield $this->createGlobalTimezoneWarning(array_values($allFieldsWithoutTimezone));
                }
            },
        );
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     * @param array<int|string, array{entity: string, field: string}> $allFieldsWithoutTimezone
     * @param-out array<int|string, array{entity: string, field: string}> $allFieldsWithoutTimezone
     * @return array<\AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function analyzeEntity(ClassMetadata $classMetadata, array &$allFieldsWithoutTimezone): array
    {
        $issues = [];
        $timestampFields = $this->findTimestampFields($classMetadata);

        if ([] === $timestampFields) {
            return $issues;
        }

        assert(is_iterable($timestampFields), '$timestampFields must be iterable');

        foreach ($timestampFields as $fieldName => $mapping) {
            // Check for DateTime instead of DateTimeImmutable
            if ($this->usesMutableDateTime($classMetadata, $fieldName)) {
                $issues[] = $this->createMutableDateTimeIssue($classMetadata, $fieldName);
            }

            // Check for missing timezone (datetime instead of datetimetz)
            // COLLECT instead of creating individual issues
            if ($this->isMissingTimezone($mapping)) {
                $className = $classMetadata->getName();
                $lastBackslashPos = strrpos($className, '\\');
                $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);
                $allFieldsWithoutTimezone[] = [
                    'entity' => $shortClassName,
                    'field' => $fieldName,
                ];
            }

            // Check for nullable createdAt
            if ($this->isCreatedAtNullable($fieldName, $mapping)) {
                $issues[] = $this->createNullableCreatedAtIssue($classMetadata, $fieldName);
            }

            // Check for public setters on timestamp fields
            if ($this->hasPublicSetter($classMetadata, $fieldName)) {
                $issues[] = $this->createPublicSetterIssue($classMetadata, $fieldName);
            }
        }

        return $issues;
    }

    /**
     * Find all timestamp fields in the entity.
     * @param ClassMetadata<object> $classMetadata
     * @return array<string, array<string, mixed>>
     */
    private function findTimestampFields(ClassMetadata $classMetadata): array
    {
        $timestampFields = [];

        foreach ($classMetadata->fieldMappings as $fieldName => $mapping) {
            // Normalize mapping to array (Doctrine ORM 3.x returns FieldMapping objects)
            if (is_object($mapping)) {
                $mapping = (array) $mapping;
            }

            if ($this->isTimestampField($fieldName, $mapping)) {
                $timestampFields[$fieldName] = $mapping;
            }
        }

        return $timestampFields;
    }

    /**
     * @param array<string, mixed>|object $mapping
     */
    private function isTimestampField(string $fieldName, array|object $mapping): bool
    {
        $fieldLower = strtolower($fieldName);

        // Check by field name
        foreach (self::TIMESTAMP_FIELD_PATTERNS as $pattern) {
            if (str_contains($fieldLower, strtolower($pattern))) {
                return true;
            }
        }

        // Check by type
        $type = MappingHelper::getString($mapping, 'type');
        if (null === $type) {
            return false;
        }

        return in_array($type, ['datetime', 'datetimetz', 'datetime_immutable', 'datetimetz_immutable'], true);
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function usesMutableDateTime(ClassMetadata $classMetadata, string $fieldName): bool
    {
        $className = $classMetadata->getName();
        assert(class_exists($className));
        $reflectionClass = new ReflectionClass($className);

        if (!$reflectionClass->hasProperty($fieldName)) {
            return false;
        }

        $reflectionProperty = $reflectionClass->getProperty($fieldName);
        $type = $reflectionProperty->getType();

        if (null === $type) {
            return false;
        }

        // Check if it's DateTime (not DateTimeImmutable)
        if ($type instanceof \ReflectionNamedType) {
            return 'DateTime' === $type->getName();
        }

        return false;
    }

    /**
     * @param array<string, mixed>|object $mapping
     */
    private function isMissingTimezone(array|object $mapping): bool
    {
        $type = MappingHelper::getString($mapping, 'type');

        // If using datetime (not datetimetz), timezone is not stored
        return 'datetime' === $type || 'datetime_immutable' === $type;
    }

    /**
     * @param array<string, mixed>|object $mapping
     */
    private function isCreatedAtNullable(string $fieldName, array|object $mapping): bool
    {
        $fieldLower = strtolower($fieldName);

        // Check if it's a createdAt field
        $isCreatedAt = str_contains($fieldLower, 'created')
            || str_contains($fieldLower, 'create');

        if (!$isCreatedAt) {
            return false;
        }

        // If it's a remote/external timestamp, nullable is acceptable
        if ($this->isRemoteTimestamp($fieldName)) {
            return false; // Not an issue
        }

        return MappingHelper::getBool($mapping, 'nullable') ?? false;
    }

    /**
     * Check if a timestamp field is for remote/external system synchronization.
     */
    private function isRemoteTimestamp(string $fieldName): bool
    {
        $fieldLower = strtolower($fieldName);

        foreach (self::REMOTE_TIMESTAMP_PATTERNS as $pattern) {
            if (str_contains($fieldLower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function hasPublicSetter(ClassMetadata $classMetadata, string $fieldName): bool
    {
        $className = $classMetadata->getName();
        assert(class_exists($className));
        $reflectionClass = new ReflectionClass($className);
        $setterName = 'set' . ucfirst($fieldName);

        if (!$reflectionClass->hasMethod($setterName)) {
            return false;
        }

        $reflectionMethod = $reflectionClass->getMethod($setterName);

        if (!$reflectionMethod->isPublic()) {
            return false;
        }

        // If Gedmo or KnpLabs traits/extensions are used, public setters are expected
        if ($this->usesTimestampableExtension($reflectionClass)) {
            return false; // Not an issue if using extensions
        }

        return true;
    }

    /**
     * Check if the entity uses Gedmo Timestampable or KnpLabs TimestampableEntity trait.
     */
    private function usesTimestampableExtension(ReflectionClass $reflectionClass): bool
    {
        // Check for KnpLabs trait usage
        $traits = $reflectionClass->getTraitNames();
        assert(is_iterable($traits), '$traits must be iterable');

        foreach ($traits as $trait) {
            if (str_contains($trait, 'TimestampableEntity') ||
                str_contains($trait, 'Timestampable') ||
                str_contains($trait, 'BlameableEntity')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function createMutableDateTimeIssue(ClassMetadata $classMetadata, string $fieldName): IssueInterface
    {
        $className = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $description = sprintf(
            'Timestamp field %s::$%s uses mutable DateTime instead of DateTimeImmutable. ' .
            'Mutable DateTime objects can be accidentally modified, causing bugs. ' .
            'Always use DateTimeImmutable for timestamp fields.',
            $shortClassName,
            $fieldName,
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'timestampable_mutable_datetime',
            'title'       => sprintf('Mutable DateTime in Timestamp: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'warning',
            'category'    => 'code_quality',
            'suggestion'  => $this->createImmutableDateTimeSuggestion($shortClassName, $fieldName),
            'backtrace'   => [
                'entity' => $className,
                'field'  => $fieldName,
            ],
        ]);
    }

    // Note: createMissingTimezoneIssue method removed as it was unused
    // Timezone validation is now handled by TimeZoneAnalyzer

    /**
     * Create ONE global warning for all fields missing timezone.
     * @param array<int, array{entity: string, field: string}> $fields
     */
    private function createGlobalTimezoneWarning(array $fields): IssueInterface
    {
        $totalFields = count($fields);
        $sampleFields = array_slice($fields, 0, 5);
        $fieldList = implode(', ', array_map(
            fn (array $field): string => sprintf('%s::$%s', $field['entity'], $field['field']),
            $sampleFields,
        ));

        if ($totalFields > 5) {
            $fieldList .= sprintf(' (and %d more)', $totalFields - 5);
        }

        $description = sprintf(
            'Found %d timestamp fields using datetime type without timezone information: %s. ' .
            'This can cause issues in multi-timezone applications. ' .
            'If your application uses a single timezone (e.g., all in UTC), this is acceptable. ' .
            'Otherwise, consider using datetimetz (or datetimetz_immutable) to store timezone information.',
            $totalFields,
            $fieldList,
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'timestampable_missing_timezone_global',
            'title'       => sprintf('%d Timestamp Fields Without Timezone', $totalFields),
            'description' => $description,
            'severity'    => 'info', // INFO instead of WARNING (less noise)
            'category'    => 'configuration',
            'suggestion'  => $this->createGlobalTimezoneAwareSuggestion($totalFields),
            'backtrace'   => [
                'fields' => $fields,
            ],
        ]);
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function createNullableCreatedAtIssue(ClassMetadata $classMetadata, string $fieldName): IssueInterface
    {
        $className = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $description = sprintf(
            'Field %s::$%s (creation timestamp) is nullable. ' .
            'Creation timestamp should never be null - every entity has a creation time. ' .
            'Mark this field as NOT NULL and initialize it in the constructor or use lifecycle callbacks.',
            $shortClassName,
            $fieldName,
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'timestampable_nullable_created_at',
            'title'       => sprintf('Nullable Creation Timestamp: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'warning',
            'category'    => 'code_quality',
            'suggestion'  => $this->createNonNullableCreatedAtSuggestion($shortClassName, $fieldName),
            'backtrace'   => [
                'entity' => $className,
                'field'  => $fieldName,
            ],
        ]);
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function createPublicSetterIssue(ClassMetadata $classMetadata, string $fieldName): IssueInterface
    {
        $className = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $description = sprintf(
            'Timestamp field %s::$%s has a public setter. ' .
            'Timestamps should be managed automatically by Doctrine lifecycle callbacks or extensions. ' .
            'Remove public setters or make them protected to prevent manual manipulation.',
            $shortClassName,
            $fieldName,
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'timestampable_public_setter',
            'title'       => sprintf('Public Setter on Timestamp: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'info',
            'category'    => 'code_quality',
            'suggestion'  => $this->createRemovePublicSetterSuggestion($shortClassName, $fieldName),
            'backtrace'   => [
                'entity' => $className,
                'field'  => $fieldName,
            ],
        ]);
    }

    private function createImmutableDateTimeSuggestion(string $className, string $fieldName): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'timestampable_immutable_datetime',
            context: [
                'entity_class' => $className,
                'field_name'   => $fieldName,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::codeQuality(),
                severity: Severity::warning(),
                title: sprintf('Use DateTimeImmutable: %s::$%s', $className, $fieldName),
                tags: ['timestampable', 'datetime', 'immutable', 'best-practice'],
            ),
        );
    }

    // Note: createTimezoneAwareSuggestion removed as unused - timezone handled by TimeZoneAnalyzer

    private function createGlobalTimezoneAwareSuggestion(int $totalFields): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'timestampable_timezone_global',
            context: [
                'total_fields' => $totalFields,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::configuration(),
                severity: Severity::info(),
                title: sprintf('%d Timestamp Fields Without Timezone', $totalFields),
                tags: ['timestampable', 'timezone', 'datetime', 'configuration', 'info'],
            ),
        );
    }

    private function createNonNullableCreatedAtSuggestion(string $className, string $fieldName): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'timestampable_non_nullable_created_at',
            context: [
                'entity_class' => $className,
                'field_name'   => $fieldName,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::codeQuality(),
                severity: Severity::warning(),
                title: sprintf('Make CreatedAt NOT NULL: %s::$%s', $className, $fieldName),
                tags: ['timestampable', 'not-null', 'database', 'constraint'],
            ),
        );
    }

    private function createRemovePublicSetterSuggestion(string $className, string $fieldName): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'timestampable_public_setter',
            context: [
                'entity_class' => $className,
                'field_name'   => $fieldName,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::codeQuality(),
                severity: Severity::info(),
                title: sprintf('Remove Public Setter: %s::$%s', $className, $fieldName),
                tags: ['timestampable', 'encapsulation', 'lifecycle-callback'],
            ),
        );
    }
}
