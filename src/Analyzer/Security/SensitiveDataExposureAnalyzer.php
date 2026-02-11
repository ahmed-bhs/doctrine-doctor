<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Security;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\PhpCodeParser;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\SecurityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Detects sensitive data that might be exposed through serialization,
 * logging, or API responses.
 * Checks for:
 * - Password, token, secret fields without proper protection
 * - __toString() methods that expose sensitive data
 * - Missing JsonIgnore/SerializedIgnore annotations
 */
class SensitiveDataExposureAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    private const array SENSITIVE_PATTERNS = [
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

    private const array METADATA_PREFIXES = [
        'is_',           // is_credit_card_saved, is_verified
        'has_',          // has_payment_method, has_token
        'should_',       // should_save_card
        'can_',          // can_save_payment
        'allow_',        // allow_credit_card
        'enable_',       // enable_password_reset
        'require_',      // require_token
    ];

    private const array METADATA_SUFFIXES = [
        '_enabled',      // credit_card_enabled
        '_allowed',      // password_reset_allowed
        '_required',     // token_required
        '_count',        // failed_password_count
        '_at',           // password_reset_at (timestamps)
        '_date',         // token_expiry_date
        '_time',         // last_password_change_time
        '_reset',        // password_reset (action, not the password)
        '_expiry',       // token_expiry
        '_expires',      // token_expires
        '_changed',      // password_changed
        '_updated',      // token_updated
        '_created',      // token_created
        '_hash',         // password_hash (already hashed, not raw password)
        '_type',         // token_type
        '_length',       // password_length (configuration)
        '_min',          // password_min_length
        '_max',          // password_max_length
        '_policy',       // password_policy
        '_rules',        // password_rules
    ];

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

                    Assert::isIterable($allMetadata, '$allMetadata must be iterable');

                    foreach ($allMetadata as $metadata) {
                        $entityIssues = $this->analyzeEntity($metadata);

                        Assert::isIterable($entityIssues, '$entityIssues must be iterable');

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

        $sensitiveFields = $this->getSensitiveFields($classMetadata);

        if ([] === $sensitiveFields) {
            return [];
        }

        if ($reflectionClass->hasMethod('__toString')) {
            $issue = $this->checkToStringMethod($entityClass, $reflectionClass, $sensitiveFields);

            if ($issue instanceof SecurityIssue) {
                $issues[] = $issue;
            }
        }

        if ($reflectionClass->hasMethod('jsonSerialize')) {
            $issue = $this->checkJsonSerializeMethod($entityClass, $reflectionClass, $sensitiveFields);

            if ($issue instanceof SecurityIssue) {
                $issues[] = $issue;
            }
        }

        if ($reflectionClass->hasMethod('toArray')) {
            $issue = $this->checkToArrayMethod($entityClass, $reflectionClass, $sensitiveFields);

            if ($issue instanceof SecurityIssue) {
                $issues[] = $issue;
            }
        }

        Assert::isIterable($sensitiveFields, '$sensitiveFields must be iterable');

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

            if ($this->isMetadataField($lowerField)) {
                continue;
            }

            foreach (self::SENSITIVE_PATTERNS as $pattern) {
                if (str_contains($lowerField, $pattern)) {
                    $sensitiveFields[] = $fieldName;
                    break;
                }
            }
        }

        return $sensitiveFields;
    }

    /**
     * Check if a field name indicates metadata/flag rather than actual sensitive data.
     */
    private function isMetadataField(string $lowerFieldName): bool
    {
        foreach (self::METADATA_PREFIXES as $prefix) {
            if (str_starts_with($lowerFieldName, $prefix)) {
                return true;
            }
        }
        return array_any(self::METADATA_SUFFIXES, fn ($suffix) => str_ends_with($lowerFieldName, (string) $suffix));
    }

    private function checkToStringMethod(
        string $entityClass,
        \ReflectionClass $reflectionClass,
        array $sensitiveFields,
    ): ?SecurityIssue {
        $reflectionMethod = $reflectionClass->getMethod('__toString');

        if ($this->phpCodeParser->detectSensitiveExposure($reflectionMethod)) {
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

        Assert::isIterable($sensitiveFields, '$sensitiveFields must be iterable');
        Assert::allString($sensitiveFields, '$sensitiveFields must contain only strings');
        /** @var array<string> $sensitiveFields */
        $exposedFields = $this->phpCodeParser->detectExposedSensitiveFields(
            $reflectionMethod,
            $sensitiveFields,
        );

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

        Assert::isIterable($sensitiveFields, '$sensitiveFields must be iterable');
        Assert::allString($sensitiveFields, '$sensitiveFields must contain only strings');
        /** @var array<string> $sensitiveFields */
        $exposedFields = $this->phpCodeParser->detectExposedSensitiveFields(
            $reflectionMethod,
            $sensitiveFields,
        );

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

            $hasProtection = false;

            if (
                false !== $docComment && (
                    str_contains($docComment, '@Ignore')
                || str_contains($docComment, '@JsonIgnore')
                || str_contains($docComment, '@SerializedIgnore')
                )
            ) {
                $hasProtection = true;
            }

            $attributes = $property->getAttributes();

            Assert::isIterable($attributes, '$attributes must be iterable');

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
        }

        return null;
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

        Assert::isIterable($exposedFields, '$exposedFields must be iterable');

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

        Assert::isIterable($exposedFields, '$exposedFields must be iterable');

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
