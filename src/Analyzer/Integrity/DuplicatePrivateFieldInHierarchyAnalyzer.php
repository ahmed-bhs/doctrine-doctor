<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Concern\MetadataAnalyzerTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\Concern\ShortClassNameTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

class DuplicatePrivateFieldInHierarchyAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;
    use ShortClassNameTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(
            function () {
                try {
                    $metadataFactory = $this->entityManager->getMetadataFactory();
                    $allMetadata = $metadataFactory->getAllMetadata();

                    $checked = [];

                    foreach ($allMetadata as $metadata) {
                        $entityClass = $metadata->getName();

                        if (isset($checked[$entityClass])) {
                            continue;
                        }

                        $checked[$entityClass] = true;

                        $issues = $this->analyzeEntity($metadata);

                        foreach ($issues as $issue) {
                            yield $issue;
                        }
                    }
                } catch (\Throwable $throwable) {
                    $this->logger?->error('DuplicatePrivateFieldInHierarchyAnalyzer failed', [
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                    ]);
                }
            },
        );
    }

    /**
     * @return array<IssueInterface>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {
        $entityClass = $classMetadata->getName();

        try {
            Assert::classExists($entityClass);
            $reflectionClass = new \ReflectionClass($entityClass);
        } catch (\Throwable) {
            return [];
        }

        $parentClass = $reflectionClass->getParentClass();

        if (false === $parentClass) {
            return [];
        }

        $mappedFieldNames = $this->getMappedFieldNames($classMetadata);
        $childPrivateFields = $this->getMappedPrivateFields($reflectionClass, $mappedFieldNames);

        if ([] === $childPrivateFields) {
            return [];
        }

        $issues = [];

        $this->collectDuplicatesFromHierarchy($reflectionClass, $parentClass, $childPrivateFields, $entityClass, $issues);

        return $issues;
    }

    /**
     * @param array<string, \ReflectionProperty> $childPrivateFields
     * @param array<IssueInterface> $issues
     */
    private function collectDuplicatesFromHierarchy(
        \ReflectionClass $childClass,
        \ReflectionClass $parentClass,
        array $childPrivateFields,
        string $entityClass,
        array &$issues,
    ): void {
        $current = $parentClass;

        while (false !== $current) {
            if (!$this->isMappedClass($current)) {
                $current = $current->getParentClass();

                continue;
            }

            $parentMappedNames = $this->getMappedFieldNamesForClass($current->getName());
            $parentPrivateFields = $this->getMappedPrivateFields($current, $parentMappedNames);

            foreach ($childPrivateFields as $fieldName => $childProperty) {
                if (!isset($parentPrivateFields[$fieldName])) {
                    continue;
                }

                $parentProperty = $parentPrivateFields[$fieldName];

                $issues[] = $this->createIssue(
                    $entityClass,
                    $fieldName,
                    $childClass->getName(),
                    $current->getName(),
                    $childProperty,
                    $parentProperty,
                );
            }

            $current = $current->getParentClass();
        }
    }

    /**
     * @return array<string>
     */
    private function getMappedFieldNames(ClassMetadata $classMetadata): array
    {
        return array_merge(
            $classMetadata->getFieldNames(),
            $classMetadata->getAssociationNames(),
        );
    }

    /**
     * @return array<string>
     */
    private function getMappedFieldNamesForClass(string $className): array
    {
        try {
            $metadata = $this->entityManager->getClassMetadata($className);

            return $this->getMappedFieldNames($metadata);
        } catch (\Throwable) {
            return [];
        }
    }

    private function isMappedClass(\ReflectionClass $reflectionClass): bool
    {
        try {
            $this->entityManager->getClassMetadata($reflectionClass->getName());

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string> $mappedFieldNames
     * @return array<string, \ReflectionProperty>
     */
    private function getMappedPrivateFields(\ReflectionClass $reflectionClass, array $mappedFieldNames): array
    {
        $fields = [];

        foreach ($reflectionClass->getProperties(\ReflectionProperty::IS_PRIVATE) as $property) {
            if ($property->getDeclaringClass()->getName() !== $reflectionClass->getName()) {
                continue;
            }

            if (!in_array($property->getName(), $mappedFieldNames, true)) {
                continue;
            }

            $fields[$property->getName()] = $property;
        }

        return $fields;
    }

    private function createIssue(
        string $entityClass,
        string $fieldName,
        string $childClass,
        string $parentClass,
        \ReflectionProperty $childProperty,
        \ReflectionProperty $parentProperty,
    ): IssueInterface {
        $shortEntity = $this->shortClassName($entityClass);
        $shortChild = $this->shortClassName($childClass);
        $shortParent = $this->shortClassName($parentClass);

        $description = sprintf(
            'Entity "%s" and its parent "%s" both declare a private field named "$%s". '
            . 'Since both fields are private, they are technically separate and can hold different values. '
            . 'However, Doctrine ClassMetadata refers to fields by name only, without considering the declaring class. '
            . 'This leads to a MappingException or unpredictable behavior with the Collection filtering API.',
            $shortChild,
            $shortParent,
            $fieldName,
        );

        $suggestion = $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/code_suggestion',
            context: [
                'description' => sprintf(
                    'Rename the private field "$%s" in either %s or %s to avoid the name collision.',
                    $fieldName,
                    $shortChild,
                    $shortParent,
                ),
                'code' => $this->buildSuggestionCode($shortChild, $shortParent, $fieldName),
                'file_path' => $this->resolveFilePath($childProperty),
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::critical(),
                title: 'Duplicate Private Field in Class Hierarchy',
                tags: ['integrity', 'orm', 'inheritance'],
            ),
        );

        $issueData = new IssueData(
            type: IssueType::DUPLICATE_PRIVATE_FIELD_IN_HIERARCHY->value,
            title: sprintf('Duplicate private field "$%s" in %s and %s', $fieldName, $shortChild, $shortParent),
            description: $description,
            severity: Severity::critical(),
            suggestion: $suggestion,
            backtrace: $this->buildBacktrace($childProperty, $parentProperty),
        );

        return $this->issueFactory->create($issueData);
    }

    private function buildSuggestionCode(string $shortChild, string $shortParent, string $fieldName): string
    {
        $code = "// PROBLEMATIC - both classes have private \${$fieldName}:\n\n";
        $code .= "// class {$shortParent} {\n";
        $code .= "//     private \${$fieldName}; // declared here\n";
        $code .= "// }\n";
        $code .= "// class {$shortChild} extends {$shortParent} {\n";
        $code .= "//     private \${$fieldName}; // also declared here - conflict!\n";
        $code .= "// }\n\n";
        $code .= "// SOLUTION 1 - Rename in child class:\n";
        $code .= "class {$shortChild} extends {$shortParent} {\n";
        $code .= "    private \$child" . ucfirst($fieldName) . ";\n";
        $code .= "}\n\n";
        $code .= "// SOLUTION 2 - Use protected in parent to share:\n";
        $code .= "class {$shortParent} {\n";
        $code .= "    protected \${$fieldName};\n";
        $code .= "}";

        return $code;
    }

    private function resolveFilePath(\ReflectionProperty $property): string
    {
        $declaringClass = $property->getDeclaringClass();
        $fileName = $declaringClass->getFileName();

        if (false === $fileName) {
            return 'unknown';
        }

        $line = $declaringClass->getStartLine();

        if (false === $line) {
            return $fileName;
        }

        return sprintf('%s:%d', $fileName, $line);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildBacktrace(
        \ReflectionProperty $childProperty,
        \ReflectionProperty $parentProperty,
    ): array {
        $backtrace = [];

        $childFile = $childProperty->getDeclaringClass()->getFileName();
        $childLine = $childProperty->getDeclaringClass()->getStartLine();

        if (false !== $childFile && false !== $childLine) {
            $backtrace[] = [
                'file' => $childFile,
                'line' => $childLine,
                'class' => $childProperty->getDeclaringClass()->getName(),
                'function' => '$' . $childProperty->getName(),
                'type' => '::',
            ];
        }

        $parentFile = $parentProperty->getDeclaringClass()->getFileName();
        $parentLine = $parentProperty->getDeclaringClass()->getStartLine();

        if (false !== $parentFile && false !== $parentLine) {
            $backtrace[] = [
                'file' => $parentFile,
                'line' => $parentLine,
                'class' => $parentProperty->getDeclaringClass()->getName(),
                'function' => '$' . $parentProperty->getName(),
                'type' => '::',
            ];
        }

        return $backtrace;
    }
}
