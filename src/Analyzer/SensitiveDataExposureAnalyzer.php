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
use AhmedBhs\DoctrineDoctor\Issue\SecurityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;

/**
 * Detects sensitive data that might be exposed through serialization,
 * logging, or API responses.
 * Checks for:
 * - Password, token, secret fields without proper protection
 * - __toString() methods that expose sensitive data
 * - Missing JsonIgnore/SerializedIgnore annotations
 */
class SensitiveDataExposureAnalyzer implements AnalyzerInterface
{
    // Sensitive field patterns
    private const SENSITIVE_PATTERNS = [
        'password',
        'passwd',
        'pwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'private_key',
        'privatekey',
        'credit_card',
        'creditcard',
        'card_number',
        'cvv',
        'ssn',
        'social_security',
        'tax_id',
        'bank_account',
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
     * @return IssueCollection<SecurityIssue>
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
                    $this->logger?->error('SensitiveDataExposureAnalyzer failed', [
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
     * @return array<SecurityIssue>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {

        $issues          = [];
        $entityClass     = $classMetadata->getName();
        $reflectionClass = $classMetadata->getReflectionClass();

        // Get all sensitive fields
        $sensitiveFields = $this->getSensitiveFields($classMetadata);

        if ([] === $sensitiveFields) {
            return [];
        }

        // Check __toString() method
        if ($reflectionClass->hasMethod('__toString')) {
            $issue = $this->checkToStringMethod($entityClass, $reflectionClass, $sensitiveFields);

            if ($issue instanceof SecurityIssue) {
                $issues[] = $issue;
            }
        }

        // Check jsonSerialize() method
        if ($reflectionClass->hasMethod('jsonSerialize')) {
            $issue = $this->checkJsonSerializeMethod($entityClass, $reflectionClass, $sensitiveFields);

            if ($issue instanceof SecurityIssue) {
                $issues[] = $issue;
            }
        }

        // Check toArray() method
        if ($reflectionClass->hasMethod('toArray')) {
            $issue = $this->checkToArrayMethod($entityClass, $reflectionClass, $sensitiveFields);

            if ($issue instanceof SecurityIssue) {
                $issues[] = $issue;
            }
        }

        // Check for missing serialization protection
        assert(is_iterable($sensitiveFields), '$sensitiveFields must be iterable');

        foreach ($sensitiveFields as $sensitiveField) {
            $issue = $this->checkSerializationProtection($entityClass, $sensitiveField, $reflectionClass);

            if ($issue instanceof SecurityIssue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * @return array<string>
     */
    private function getSensitiveFields(ClassMetadata $classMetadata): array
    {

        $sensitiveFields = [];

        foreach ($classMetadata->getFieldNames() as $fieldName) {
            $lowerField = strtolower($fieldName);

            foreach (self::SENSITIVE_PATTERNS as $pattern) {
                if (str_contains($lowerField, $pattern)) {
                    $sensitiveFields[] = $fieldName;
                    break;
                }
            }
        }

        return $sensitiveFields;
    }

    private function checkToStringMethod(
        string $entityClass,
        \ReflectionClass $reflectionClass,
        array $sensitiveFields,
    ): ?SecurityIssue {
        $reflectionMethod = $reflectionClass->getMethod('__toString');
        $source           = $this->getMethodSource($reflectionMethod);

        if (null === $source) {
            return null;
        }

        // Check if __toString uses json_encode or serialize on $this
        if (
            1 === preg_match('/json_encode\s*\(\s*\$this\s*\)/i', $source)
            || 1 === preg_match('/serialize\s*\(\s*\$this\s*\)/i', $source)
        ) {
            return new SecurityIssue([
                'title'       => 'Sensitive data exposure in __toString() method',
                'description' => sprintf(
                    'Entity "%s" has a __toString() method that serializes the entire object. ' .
                    'This entity contains sensitive fields (%s) that will be exposed in logs, ' .
                    'error messages, and debug output. This is a critical security vulnerability.',
                    $this->getShortClassName($entityClass),
                    implode(', ', array_map(fn (string $field): string => '$' . $field, $sensitiveFields)),
                ),
                'severity'   => 'critical',
                'suggestion' => $this->createToStringSuggestion($entityClass, $reflectionMethod),
                'backtrace'  => [
                    'file' => $reflectionMethod->getFileName(),
                    'line' => $reflectionMethod->getStartLine(),
                ],
                'queries' => [],
            ]);
        }

        return null;
    }

    private function checkJsonSerializeMethod(
        string $entityClass,
        \ReflectionClass $reflectionClass,
        array $sensitiveFields,
    ): ?SecurityIssue {
        $reflectionMethod = $reflectionClass->getMethod('jsonSerialize');
        $source           = $this->getMethodSource($reflectionMethod);

        if (null === $source) {
            return null;
        }

        // Check if any sensitive field is exposed
        $exposedFields = [];

        assert(is_iterable($sensitiveFields), '$sensitiveFields must be iterable');

        foreach ($sensitiveFields as $sensitiveField) {
            if (1 === preg_match('/[\'"]' . $sensitiveField . '[\'"]|->get' . ucfirst((string) $sensitiveField) . '/i', $source)) {
                $exposedFields[] = $sensitiveField;
            }
        }

        if ([] !== $exposedFields) {
            return new SecurityIssue([
                'title'       => 'Sensitive data in jsonSerialize() method',
                'description' => sprintf(
                    'Entity "%s" exposes sensitive fields in jsonSerialize(): %s. ' .
                    'These fields will be included in JSON API responses, potentially exposing ' .
                    'passwords, tokens, or other sensitive data to clients.',
                    $this->getShortClassName($entityClass),
                    implode(', ', array_map(fn (string $field): string => '$' . $field, $exposedFields)),
                ),
                'severity'   => 'critical',
                'suggestion' => $this->createJsonSerializeSuggestion($entityClass, $reflectionMethod, $exposedFields),
                'backtrace'  => [
                    'file' => $reflectionMethod->getFileName(),
                    'line' => $reflectionMethod->getStartLine(),
                ],
                'queries' => [],
            ]);
        }

        return null;
    }

    private function checkToArrayMethod(
        string $entityClass,
        \ReflectionClass $reflectionClass,
        array $sensitiveFields,
    ): ?SecurityIssue {
        $reflectionMethod = $reflectionClass->getMethod('toArray');
        $source           = $this->getMethodSource($reflectionMethod);

        if (null === $source) {
            return null;
        }

        // Check if any sensitive field is exposed
        $exposedFields = [];

        assert(is_iterable($sensitiveFields), '$sensitiveFields must be iterable');

        foreach ($sensitiveFields as $sensitiveField) {
            if (1 === preg_match('/[\'"]' . $sensitiveField . '[\'"]|->get' . ucfirst((string) $sensitiveField) . '/i', $source)) {
                $exposedFields[] = $sensitiveField;
            }
        }

        if ([] !== $exposedFields) {
            return new SecurityIssue([
                'title'       => 'Sensitive data in toArray() method',
                'description' => sprintf(
                    'Entity "%s" exposes sensitive fields in toArray(): %s. ' .
                    'This method is often used for serialization, logging, or API responses, ' .
                    'which can leak sensitive data.',
                    $this->getShortClassName($entityClass),
                    implode(', ', array_map(fn (string $field): string => '$' . $field, $exposedFields)),
                ),
                'severity'   => 'critical',
                'suggestion' => $this->createToArraySuggestion($entityClass, $reflectionMethod, $exposedFields),
                'backtrace'  => [
                    'file' => $reflectionMethod->getFileName(),
                    'line' => $reflectionMethod->getStartLine(),
                ],
                'queries' => [],
            ]);
        }

        return null;
    }

    private function checkSerializationProtection(
        string $entityClass,
        string $fieldName,
        \ReflectionClass $reflectionClass,
    ): ?SecurityIssue {
        try {
            $property   = $reflectionClass->getProperty($fieldName);
            $docComment = $property->getDocComment();

            // Check for JsonIgnore or similar annotations
            $hasProtection = false;

            // Check annotations
            if (
                false !== $docComment && (
                    str_contains($docComment, '@Ignore')
                || str_contains($docComment, '@JsonIgnore')
                || str_contains($docComment, '@SerializedIgnore')
                )
            ) {
                $hasProtection = true;
            }

            // Check PHP 8 attributes
            $attributes = $property->getAttributes();

            assert(is_iterable($attributes), '$attributes must be iterable');

            foreach ($attributes as $attribute) {
                $attrName = $attribute->getName();

                if (str_contains((string) $attrName, 'Ignore')) {
                    $hasProtection = true;
                    break;
                }
            }

            if (!$hasProtection) {
                return new SecurityIssue([
                    'title'       => sprintf('Unprotected sensitive field: %s::$%s', $this->getShortClassName($entityClass), $fieldName),
                    'description' => sprintf(
                        'The sensitive field "$%s" in entity "%s" lacks serialization protection. ' .
                        'Without @JsonIgnore or #[Ignore] annotations, this field will be included in ' .
                        'JSON serialization, API responses, and logs. Additionally, consider using ' .
                        '#[SensitiveParameter] on setter methods to prevent values from appearing in stack traces. ' .
                        'Add appropriate annotations to prevent data leakage.',
                        $fieldName,
                        $this->getShortClassName($entityClass),
                    ),
                    'severity'   => 'warning',
                    'suggestion' => $this->createProtectionSuggestion($entityClass, $fieldName),
                    'backtrace'  => [
                        'file' => $property->getDeclaringClass()->getFileName(),
                        'line' => $property->getDeclaringClass()->getStartLine(),
                    ],
                    'queries' => [],
                ]);
            }
        } catch (\ReflectionException) {
            // Property doesn't exist
        }

        return null;
    }

    private function getMethodSource(\ReflectionMethod $reflectionMethod): ?string
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

        return implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));
    }

    private function createToStringSuggestion(string $entityClass, \ReflectionMethod $reflectionMethod): SuggestionInterface
    {
        $shortClassName = $this->getShortClassName($entityClass);

        $code = "// In {$shortClassName} class:

";
        $code .= "public function __toString(): string
";
        $code .= "{
";
        $code .= "    //  Only expose non-sensitive data
";
        $code .= "    return sprintf(
";
        $code .= "        '%s #%d',
";
        $code .= "        self::class,
";
        $code .= "        \$this->id ?? 0
";
        $code .= "    );
";
        $code .= "    
";
        $code .= "    // NEVER do this:
";
        $code .= "    // return json_encode(\$this); // Exposes ALL fields including passwords!
";
        $code .= '}';

        return $this->suggestionFactory->createCodeSuggestion(
            description: 'Rewrite __toString() to only expose non-sensitive data',
            code: $code,
            filePath: $this->getFileLocation($reflectionMethod),
        );
    }

    private function createJsonSerializeSuggestion(
        string $entityClass,
        \ReflectionMethod $reflectionMethod,
        array $exposedFields,
    ): SuggestionInterface {
        $shortClassName = $this->getShortClassName($entityClass);

        $code = "// In {$shortClassName} class:

";
        $code .= "public function jsonSerialize(): array
";
        $code .= "{
";
        $code .= "    return [
";
        $code .= "        'id' => \$this->id,
";
        $code .= "        'name' => \$this->name,
";
        $code .= "        //  Only include public, non-sensitive data
";
        $code .= "        
";
        $code .= "        // DO NOT include:
";

        assert(is_iterable($exposedFields), '$exposedFields must be iterable');

        foreach ($exposedFields as $exposedField) {
            $code .= "        // '{$exposedField}' => \$this->{$exposedField}, // SENSITIVE!
";
        }

        $code .= "    ];
";
        $code .= '}';

        return $this->suggestionFactory->createCodeSuggestion(
            description: 'Remove sensitive fields from jsonSerialize()',
            code: $code,
            filePath: $this->getFileLocation($reflectionMethod),
        );
    }

    private function createToArraySuggestion(
        string $entityClass,
        \ReflectionMethod $reflectionMethod,
        array $exposedFields,
    ): SuggestionInterface {
        $shortClassName = $this->getShortClassName($entityClass);

        $code = "// In {$shortClassName} class:

";
        $code .= "public function toArray(): array
";
        $code .= "{
";
        $code .= "    return [
";
        $code .= "        'id' => \$this->id,
";
        $code .= "        //  Only include safe fields
";
        $code .= "        
";
        $code .= "        // DO NOT include sensitive fields:
";

        assert(is_iterable($exposedFields), '$exposedFields must be iterable');

        foreach ($exposedFields as $exposedField) {
            $code .= "        // '{$exposedField}' => \$this->{$exposedField}, // SENSITIVE DATA!
";
        }

        $code .= "    ];
";
        $code .= '}';

        return $this->suggestionFactory->createCodeSuggestion(
            description: 'Remove sensitive fields from toArray()',
            code: $code,
            filePath: $this->getFileLocation($reflectionMethod),
        );
    }

    private function createProtectionSuggestion(string $entityClass, string $fieldName): SuggestionInterface
    {
        $shortClassName = $this->getShortClassName($entityClass);
        $capitalizedFieldName = ucfirst($fieldName);

        $code = "// In {$shortClassName} class:

";
        $code .= "use Symfony\Component\Serializer\Annotation\Ignore;
";
        $code .= "use SensitiveParameter;

";

        $code .= "// Step 1: Protect the property from serialization
";
        $code .= "#[Ignore]
";
        $code .= "private string \${$fieldName};

";

        $code .= "// Step 2: Protect the setter parameter from stack traces (PHP 8.2+)
";
        $code .= "public function set{$capitalizedFieldName}(#[SensitiveParameter] string \${$fieldName}): self
";
        $code .= "{
";
        $code .= "    \$this->{$fieldName} = \${$fieldName};
";
        $code .= "    return \$this;
";
        $code .= "}

";

        $code .= "// Or with Doctrine annotations:
";
        $code .= "/**
";
        $code .= " * @Ignore
";
        $code .= " * @Column(type=\"string\")
";
        $code .= " */
";
        $code .= sprintf('private $%s;', $fieldName);

        return $this->suggestionFactory->createCodeSuggestion(
            description: 'Add serialization protection and sensitive parameter attributes',
            code: $code,
            filePath: $entityClass,
        );
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }

    private function getFileLocation(\ReflectionMethod $reflectionMethod): string
    {
        $filename = $reflectionMethod->getFileName();
        $line = $reflectionMethod->getStartLine();

        if (false === $filename || false === $line) {
            return 'unknown';
        }

        return sprintf('%s:%d', $filename, $line);
    }
}
