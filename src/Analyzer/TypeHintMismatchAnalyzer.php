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
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Detects type hint mismatches between Doctrine column types and PHP property types.
 * This is a critical issue that causes:
 * - Unnecessary UPDATE statements on every flush
 * - Increased database locks and deadlock risks
 * - Performance degradation
 * - Increased infrastructure costs
 * Example:
 * #[ORM\Column(type: 'decimal')]  // Returns string from DB
 * public float $price;              // PHP type is float
 * The UnitOfWork uses strict comparison (===) which considers "5.0" !== 5.0,
 * causing Doctrine to think the value changed when it hasn't.
 */
final class TypeHintMismatchAnalyzer implements AnalyzerInterface
{
    /**
     * Mapping of Doctrine types to expected PHP types.
     */
    private const TYPE_MAPPINGS = [
        // Numeric types
        'integer'  => ['int', 'integer'],
        'smallint' => ['int', 'integer'],
        'bigint'   => ['int', 'integer', 'string'], // Can be string for very large values
        'decimal'  => ['string'], // IMPORTANT: decimal is always string in PHP
        'float'    => ['float', 'double'],

        // String types
        'string' => ['string'],
        'text'   => ['string'],
        'guid'   => ['string'],

        // Date/Time types
        'datetime'             => ['DateTime', 'DateTimeImmutable', 'DateTimeInterface'],
        'datetime_immutable'   => ['DateTimeImmutable'],
        'date'                 => ['DateTime', 'DateTimeImmutable', 'DateTimeInterface'],
        'date_immutable'       => ['DateTimeImmutable'],
        'time'                 => ['DateTime', 'DateTimeImmutable', 'DateTimeInterface'],
        'time_immutable'       => ['DateTimeImmutable'],
        'datetimetz'           => ['DateTime', 'DateTimeImmutable', 'DateTimeInterface'],
        'datetimetz_immutable' => ['DateTimeImmutable'],

        // Boolean
        'boolean' => ['bool', 'boolean'],

        // Binary
        'binary' => ['string', 'resource'],
        'blob'   => ['string', 'resource'],

        // Array/Object
        'array'        => ['array'],
        'simple_array' => ['array'],
        'json'         => ['array'],
        'object'       => ['object'],
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

                foreach ($classMetadataFactory->getAllMetadata() as $classMetadatum) {
                    if ($classMetadatum->isMappedSuperclass) {
                        continue;
                    }

                    if ($classMetadatum->isEmbeddedClass) {
                        continue;
                    }

                    $entityIssues = $this->analyzeEntity($classMetadatum);

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
        return 'Type Hint Mismatch Detector';
    }

    public function getCategory(): string
    {
        return 'code_quality';
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     * @return array<\AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {

        $issues = [];

        foreach ($classMetadata->fieldMappings as $fieldName => $mapping) {
            // Normalize mapping to array (Doctrine ORM 3.x returns FieldMapping objects)
            if (is_object($mapping)) {
                $mapping = (array) $mapping;
            }

            $mismatch = $this->detectTypeMismatch($classMetadata, $fieldName, $mapping);

            if (null !== $mismatch) {
                $issues[] = $this->createTypeMismatchIssue(
                    $classMetadata,
                    $fieldName,
                    $mismatch['expected'],
                    $mismatch['actual'],
                    $mismatch['doctrineType'],
                );
            }
        }

        return $issues;
    }

    /**
     * Detect type mismatch between Doctrine column type and PHP property type.
     * @param array<string, mixed>|object $mapping
     * @return array{expected: string, actual: string, doctrineType: string}|null
     */
    private function detectTypeMismatch(ClassMetadata $classMetadata, string $fieldName, array|object $mapping): ?array
    {
        $typeInfo = $this->getExpectedTypesAndPropertyType($classMetadata, $fieldName, $mapping);
        if (null === $typeInfo) {
            return null;
        }

        ['expectedTypes' => $expectedTypes, 'propertyType' => $propertyType, 'doctrineType' => $doctrineType] = $typeInfo;

        // Handle union types (PHP 8.0+)
        if ($propertyType instanceof \ReflectionUnionType) {
            return $this->checkUnionTypeMismatch($propertyType, $expectedTypes, $doctrineType);
        }

        // Handle simple types
        return $this->checkSimpleTypeMismatch($propertyType, $expectedTypes, $doctrineType);
    }

    /**
     * Get expected types and property type for comparison.
     * @param array<string, mixed>|object $mapping
     * @return array{expectedTypes: array<int, string>, propertyType: \ReflectionType, doctrineType: string}|null
     */
    private function getExpectedTypesAndPropertyType(
        ClassMetadata $classMetadata,
        string $fieldName,
        array|object $mapping,
    ): ?array {
        $doctrineType = MappingHelper::getString($mapping, 'type');
        if (null === $doctrineType) {
            return null;
        }

        $expectedTypes = self::TYPE_MAPPINGS[$doctrineType] ?? null;
        if (null === $expectedTypes) {
            return null;
        }

        $reflectionClass = $classMetadata->getReflectionClass();
        if (!$reflectionClass->hasProperty($fieldName)) {
            return null;
        }

        $reflectionProperty = $reflectionClass->getProperty($fieldName);
        $propertyType = $reflectionProperty->getType();

        if (null === $propertyType) {
            return null;
        }

        return [
            'expectedTypes' => $expectedTypes,
            'propertyType' => $propertyType,
            'doctrineType' => $doctrineType,
        ];
    }

    /**
     * Check union type for mismatches.
     * @param array<int, string> $expectedTypes
     * @return array{expected: string, actual: string, doctrineType: string}|null
     */
    private function checkUnionTypeMismatch(
        \ReflectionUnionType $propertyType,
        array $expectedTypes,
        string $doctrineType,
    ): ?array {
        $actualTypes = array_map(
            function (\ReflectionIntersectionType|\ReflectionNamedType $type): string {
                if ($type instanceof \ReflectionNamedType) {
                    return $type->getName();
                }
                // For intersection types, get the first type name as a representation
                $intersectionTypes = $type->getTypes();
                $firstType = $intersectionTypes[0];
                return $firstType instanceof \ReflectionNamedType ? $firstType->getName() : 'mixed';
            },
            $propertyType->getTypes(),
        );

        // Check if any of the union types match expected types
        assert(is_iterable($actualTypes), '$actualTypes must be iterable');

        foreach ($actualTypes as $actualType) {
            if ($this->isCompatibleType($actualType, $expectedTypes)) {
                return null; // At least one type matches
            }
        }

        return [
            'expected'     => implode('|', $expectedTypes),
            'actual'       => implode('|', $actualTypes),
            'doctrineType' => $doctrineType,
        ];
    }

    /**
     * Check simple type for mismatches.
     * @param array<int, string> $expectedTypes
     * @return array{expected: string, actual: string, doctrineType: string}|null
     */
    private function checkSimpleTypeMismatch(
        \ReflectionType $propertyType,
        array $expectedTypes,
        string $doctrineType,
    ): ?array {
        $actualType = $this->extractTypeName($propertyType);
        if (null === $actualType) {
            return null;
        }

        if ($this->isCompatibleType($actualType, $expectedTypes)) {
            return null;
        }

        return [
            'expected'     => implode('|', $expectedTypes),
            'actual'       => $actualType,
            'doctrineType' => $doctrineType,
        ];
    }

    /**
     * Extract type name from reflection type.
     */
    private function extractTypeName(\ReflectionType $propertyType): ?string
    {
        if ($propertyType instanceof \ReflectionNamedType) {
            return $propertyType->getName();
        }

        if ($propertyType instanceof \ReflectionIntersectionType) {
            $intersectionTypes = $propertyType->getTypes();
            $firstType = $intersectionTypes[0];
            assert($firstType instanceof \ReflectionNamedType, 'Intersection type must contain NamedTypes');
            return $firstType->getName();
        }

        return null;
    }

    private function isCompatibleType(string $actualType, array $expectedTypes): bool
    {
        // Normalize type names
        $actualType = $this->normalizeTypeName($actualType);

        assert(is_iterable($expectedTypes), '$expectedTypes must be iterable');

        foreach ($expectedTypes as $expectedType) {
            $expectedType = $this->normalizeTypeName($expectedType);

            if ($actualType === $expectedType) {
                return true;
            }

            // Check class hierarchy for objects
            if (class_exists($actualType) && class_exists($expectedType) && is_a($actualType, $expectedType, true)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeTypeName(string $type): string
    {
        // Handle fully qualified class names
        $type = ltrim($type, '\\');

        // Normalize built-in type aliases
        return match ($type) {
            'integer' => 'int',
            'boolean' => 'bool',
            'double'  => 'float',
            default   => $type,
        };
    }

    private function createTypeMismatchIssue(
        ClassMetadata $classMetadata,
        string $fieldName,
        string $expectedType,
        string $actualType,
        string $doctrineType,
    ): IssueInterface {
        $className      = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        // Determine severity based on the type of mismatch
        $severity = $this->determineSeverity($doctrineType, $actualType);

        $description = sprintf(
            'Entity %s::$%s has type hint "%s" but Doctrine type "%s" expects "%s". ' .
            'This causes the UnitOfWork to detect false changes and execute unnecessary UPDATE statements on every flush.',
            $shortClassName,
            $fieldName,
            $actualType,
            $doctrineType,
            $expectedType,
        );

        // Create suggestion based on the specific mismatch
        $suggestion = $this->createSuggestionForMismatch(
            $fieldName,
            $doctrineType,
            $actualType,
            $expectedType,
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'type_hint_mismatch',
            'title'       => sprintf('Type Mismatch: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => $severity,
            'category'    => 'code_quality',
            'suggestion'  => $suggestion,
            'backtrace'   => [
                'entity'        => $className,
                'field'         => $fieldName,
                'doctrine_type' => $doctrineType,
                'php_type'      => $actualType,
                'expected_type' => $expectedType,
            ],
        ]);
    }

    private function determineSeverity(string $doctrineType, string $actualType): Severity
    {
        // Decimal with float is CRITICAL (money handling issue + performance)
        if ('decimal' === $doctrineType && in_array($actualType, ['float', 'double'], true)) {
            return Severity::CRITICAL;
        }

        // Other mismatches are warnings (performance issue only)
        return Severity::WARNING;
    }

    private function createSuggestionForMismatch(
        string $fieldName,
        string $doctrineType,
        string $actualType,
        string $expectedType,
    ): mixed {
        // Special case: decimal type with float
        if ('decimal' === $doctrineType && in_array($actualType, ['float', 'double'], true)) {
            return $this->createDecimalFloatSuggestion($fieldName);
        }

        // General type mismatch
        return $this->createGeneralMismatchSuggestion(
            $fieldName,
            $doctrineType,
            $actualType,
            $expectedType,
        );
    }

    private function createDecimalFloatSuggestion(string $fieldName): mixed
    {
        $goodCode = <<<PHP
            #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
            public string \${$fieldName};

            //  Types match
            //  No precision loss
            //  No unnecessary UPDATE
            PHP;

        $moneyLibraryCode = <<<PHP
            use Money\Money;

            #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
            private string \${$fieldName}Internal;

            public function get{$this->toPascalCase($fieldName)}(): Money
            {
                return Money::EUR(
                    (int) bcmul(\$this->{$fieldName}Internal, '100', 0)
                );
            }

            public function set{$this->toPascalCase($fieldName)}(Money \$money): void
            {
                \$this->{$fieldName}Internal = bcdiv(
                    (string) \$money->getAmount(),
                    '100',
                    2
                );
            }
            PHP;

        $suggestionMetadata = new SuggestionMetadata(
            type: SuggestionType::codeQuality(),
            severity: Severity::warning(),
            title: 'Fix Decimal/Float Type Mismatch',
        );

        return $this->suggestionFactory->createFromTemplate(
            'type_hint_decimal_float_mismatch',
            [
                'options' => [
                    [
                        'title'       => 'Change Property Type to String',
                        'description' => 'Use string for decimal values to maintain precision and avoid false change detection.',
                        'code'        => $goodCode,
                        'pros'        => [
                            'No precision loss',
                            'No unnecessary UPDATEs',
                            'Type safety maintained',
                        ],
                        'cons' => [
                            'Need to use bcmath functions for calculations',
                            'String manipulation in business logic',
                        ],
                    ],
                    [
                        'title'       => 'Use Money Library (Recommended for Money)',
                        'description' => 'Use moneyphp/money library for proper money handling.',
                        'code'        => $moneyLibraryCode,
                        'pros'        => [
                            'Industry standard for money',
                            'Built-in currency support',
                            'Safe arithmetic operations',
                            'No floating point issues',
                        ],
                        'cons' => [
                            'Additional dependency',
                            'More complex initial setup',
                        ],
                    ],
                ],
                'warning_message'     => 'NEVER use float for money! Floating point arithmetic has precision issues that can cause financial discrepancies.',
                'info_message'        => 'Each unnecessary UPDATE increases database load, lock contention, and can lead to deadlocks in high-traffic applications.',
                'money_library_link'  => 'https://github.com/moneyphp/money',
                'doctrine_types_link' => 'https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/basic-mapping.html#doctrine-mapping-types',
            ],
            $suggestionMetadata,
        );
    }

    private function createGeneralMismatchSuggestion(
        string $fieldName,
        string $doctrineType,
        string $actualType,
        string $expectedType,
    ): mixed {
        $badCode = <<<PHP
            #[ORM\Column(type: '{$doctrineType}')]
            public {$actualType} \${$fieldName};
            PHP;

        $goodCode = <<<PHP
            #[ORM\Column(type: '{$doctrineType}')]
            public {$expectedType} \${$fieldName};
            PHP;

        $description = sprintf(
            'The property type should match what Doctrine returns from the database. ' .
            'Doctrine type "%s" returns "%s", but your property is typed as "%s".',
            $doctrineType,
            $expectedType,
            $actualType,
        );

        $performanceImpact = [
            'Unnecessary UPDATE on every flush',
            'Increased database write load',
            'More UPDATE locks (deadlock risk)',
            'Higher infrastructure costs',
        ];

        $suggestionMetadata = new SuggestionMetadata(
            type: SuggestionType::codeQuality(),
            severity: Severity::warning(),
            title: 'Synchronize Property Type with Column Type',
        );

        return $this->suggestionFactory->createFromTemplate(
            'type_hint_mismatch',
            [
                'bad_code'           => $badCode,
                'good_code'          => $goodCode,
                'description'        => $description,
                'performance_impact' => $performanceImpact,
            ],
            $suggestionMetadata,
        );
    }

    private function toPascalCase(string $string): string
    {
        return str_replace('_', '', ucwords($string, '_'));
    }
}
