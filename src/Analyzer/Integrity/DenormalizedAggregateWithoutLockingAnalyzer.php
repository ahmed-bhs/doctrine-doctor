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
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\PhpCodeParser;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

class DenormalizedAggregateWithoutLockingAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;
    use ShortClassNameTrait;

    private const array NUMERIC_TYPES = [
        'integer',
        'smallint',
        'bigint',
        'float',
        'decimal',
    ];

    private readonly PhpCodeParser $phpCodeParser;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly IssueFactoryInterface $issueFactory,
        ?PhpCodeParser $phpCodeParser = null,
    ) {
        $this->phpCodeParser = $phpCodeParser ?? new PhpCodeParser();
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(
            function () {
                $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

                foreach ($allMetadata as $metadata) {
                    if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                        continue;
                    }

                    yield from $this->analyzeEntity($metadata);
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Denormalized Aggregate Without Locking Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects denormalized aggregate fields updated alongside collections without a locking mechanism';
    }

    /**
     * @return iterable<IntegrityIssue>
     */
    private function analyzeEntity(ClassMetadata $metadata): iterable
    {
        if ($metadata->isVersioned) {
            return;
        }

        $numericFields = $this->getNumericFields($metadata);

        if ([] === $numericFields) {
            return;
        }

        $collectionFields = $this->getCollectionFields($metadata);

        if ([] === $collectionFields) {
            return;
        }

        yield from $this->scanMethods($metadata, $numericFields, $collectionFields);
    }

    /**
     * @param list<string> $numericFields
     * @param list<string> $collectionFields
     * @return iterable<IntegrityIssue>
     */
    private function scanMethods(
        ClassMetadata $metadata,
        array $numericFields,
        array $collectionFields,
    ): iterable {
        $reflectionClass = $metadata->getReflectionClass();

        foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $metadata->getName()) {
                continue;
            }

            if ($method->isConstructor() || $method->isDestructor() || $method->isStatic()) {
                continue;
            }

            $result = $this->phpCodeParser->detectAggregateFieldMutation(
                $method,
                $numericFields,
                $collectionFields,
            );

            if (!$result['has_both']) {
                continue;
            }

            yield $this->createIssue(
                $metadata,
                $method,
                $result['mutated_fields'],
                $result['accessed_collections'],
            );
        }
    }

    /**
     * @return list<string>
     */
    private function getNumericFields(ClassMetadata $metadata): array
    {
        $numericFields = [];

        foreach ($metadata->getFieldNames() as $fieldName) {
            $mapping = $metadata->getFieldMapping($fieldName);
            $type = MappingHelper::getString($mapping, 'type') ?? '';

            if (\in_array($type, self::NUMERIC_TYPES, true)) {
                $numericFields[] = $fieldName;
            }
        }

        return $numericFields;
    }

    /**
     * @return list<string>
     */
    private function getCollectionFields(ClassMetadata $metadata): array
    {
        $collectionFields = [];

        foreach ($metadata->getAssociationMappings() as $fieldName => $mapping) {
            if ($this->isCollectionAssociation($mapping)) {
                $collectionFields[] = $fieldName;
            }
        }

        return $collectionFields;
    }

    private function isCollectionAssociation(array|object $mapping): bool
    {
        if (\is_object($mapping)) {
            $className = $mapping::class;

            return str_contains($className, 'OneToManyAssociationMapping')
                || str_contains($className, 'ManyToManyAssociationMapping')
                || str_contains($className, 'ManyToManyOwningSideMapping')
                || str_contains($className, 'ManyToManyInverseSideMapping');
        }

        $type = MappingHelper::getInt($mapping, 'type');

        return ClassMetadata::ONE_TO_MANY === $type || ClassMetadata::MANY_TO_MANY === $type;
    }

    /**
     * @param list<string> $mutatedFields
     * @param list<string> $accessedCollections
     */
    private function createIssue(
        ClassMetadata $metadata,
        \ReflectionMethod $method,
        array $mutatedFields,
        array $accessedCollections,
    ): IntegrityIssue {
        $shortName = $metadata->getReflectionClass()->getShortName();
        $entityClass = $metadata->getName();

        $description = DescriptionHighlighter::highlight(
            '{class}::{method}() modifies aggregate field(s) {fields} alongside collection(s) {collections} '
            . 'without a locking mechanism (#[ORM\Version]). Under concurrent requests, two processes can '
            . 'read the same stale aggregate value, both compute a new value, and both flush — resulting in '
            . 'a lost update. This is explicitly documented by Doctrine as a race condition risk for '
            . 'denormalized schemas.',
            [
                'class' => $shortName,
                'method' => $method->getName(),
                'fields' => implode(', ', array_map(fn (string $f): string => '$' . $f, $mutatedFields)),
                'collections' => implode(', ', array_map(fn (string $c): string => '$' . $c, $accessedCollections)),
            ],
        );

        $issueData = new IssueData(
            type: IssueType::DENORMALIZED_AGGREGATE_WITHOUT_LOCKING->value,
            title: sprintf(
                'Denormalized Aggregate Without Locking: %s::%s()',
                $shortName,
                $method->getName(),
            ),
            description: $description,
            severity: Severity::warning(),
            suggestion: $this->createSuggestion($entityClass, $shortName, $method, $mutatedFields, $accessedCollections),
            queries: [],
            backtrace: $this->createMethodBacktrace($method),
        );

        /** @var IntegrityIssue $issue */
        $issue = $this->issueFactory->createFromArray($issueData->toArray() + [
            'entity' => $entityClass,
        ]);

        return $issue;
    }

    /**
     * @param list<string> $mutatedFields
     * @param list<string> $accessedCollections
     */
    private function createSuggestion(
        string $entityClass,
        string $shortName,
        \ReflectionMethod $method,
        array $mutatedFields,
        array $accessedCollections,
    ): \AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/denormalized_aggregate_without_locking',
            context: [
                'entity_class' => $shortName,
                'entity_fqcn' => $entityClass,
                'method_name' => $method->getName(),
                'mutated_fields' => $mutatedFields,
                'accessed_collections' => $accessedCollections,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::warning(),
                title: 'Add Locking Mechanism for Denormalized Aggregate',
                tags: ['race-condition', 'locking', 'aggregate', 'data-integrity'],
            ),
        );
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function createMethodBacktrace(\ReflectionMethod $method): ?array
    {
        $fileName = $method->getFileName();
        $startLine = $method->getStartLine();

        if (false === $fileName || false === $startLine) {
            return null;
        }

        return [[
            'file' => $fileName,
            'line' => $startLine,
            'class' => $method->getDeclaringClass()->getName(),
            'function' => $method->getName(),
            'type' => '->',
        ]];
    }
}
