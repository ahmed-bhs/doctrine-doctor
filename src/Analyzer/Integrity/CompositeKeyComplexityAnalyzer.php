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
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Detects composite primary keys in Doctrine entities.
 *
 * Composite keys cause several issues with Doctrine ORM:
 * - getReference() does not work with composite keys
 * - Associations targeting the entity must map all key columns
 * - Identity map lookups are slower
 * - No GeneratedValue support
 *
 * Severity:
 * - 2 columns: WARNING
 * - 3+ columns: CRITICAL
 */
class CompositeKeyComplexityAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;
    use ShortClassNameTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly IssueFactoryInterface $issueFactory,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

                foreach ($allMetadata as $metadata) {
                    if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                        continue;
                    }

                    $issue = $this->analyzeEntity($metadata);
                    if (null !== $issue) {
                        yield $issue;
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Composite Key Complexity Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects composite primary keys that complicate Doctrine ORM usage and suggest surrogate keys';
    }

    private function analyzeEntity(ClassMetadata $metadata): ?IntegrityIssue
    {
        $identifierFields = $metadata->getIdentifierFieldNames();
        $columnCount = count($identifierFields);

        if ($columnCount < 2) {
            return null;
        }

        $entityClass = $metadata->getName();
        $shortName = $metadata->getReflectionClass()->getShortName();
        $severity = $columnCount >= 3 ? Severity::critical() : Severity::warning();

        $referencedBy = $this->findEntitiesReferencingCompositeKey($metadata);

        $description = sprintf(
            "Entity '%s' uses a composite primary key with %d columns (%s). " .
            'Composite keys limit Doctrine features: getReference() is unavailable, ' .
            'associations require all key columns, and identity map lookups are slower.',
            $entityClass,
            $columnCount,
            implode(', ', $identifierFields),
        );

        if ([] !== $referencedBy) {
            $description .= sprintf(
                ' This entity is referenced by %d other entit%s (%s), which must all map the full composite key.',
                count($referencedBy),
                1 === count($referencedBy) ? 'y' : 'ies',
                implode(', ', array_map(fn (string $fqcn): string => $this->shortClassName($fqcn), $referencedBy)),
            );
            $severity = Severity::critical();
        }

        $issueData = new IssueData(
            type: IssueType::COMPOSITE_KEY_COMPLEXITY->value,
            title: sprintf('Composite Primary Key in %s (%d columns)', $shortName, $columnCount),
            description: $description,
            severity: $severity,
            suggestion: $this->createSuggestion($entityClass, $shortName, $identifierFields, $referencedBy),
            queries: [],
            backtrace: $this->createEntityBacktrace($metadata),
        );

        /** @var IntegrityIssue $issue */
        $issue = $this->issueFactory->createFromArray($issueData->toArray() + ['entity' => $entityClass]);

        return $issue;
    }

    /**
     * @return list<string>
     */
    private function findEntitiesReferencingCompositeKey(ClassMetadata $targetMetadata): array
    {
        $targetClass = $targetMetadata->getName();
        $referencedBy = [];

        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }

            if ($metadata->getName() === $targetClass) {
                continue;
            }

            foreach ($metadata->getAssociationMappings() as $mapping) {
                if (MappingHelper::getString($mapping, 'targetEntity') === $targetClass) {
                    $referencedBy[] = $metadata->getName();
                    break;
                }
            }
        }

        return $referencedBy;
    }

    private function createSuggestion(
        string $entityClass,
        string $shortName,
        array $identifierFields,
        array $referencedBy,
    ): SuggestionInterface {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/composite_key_complexity',
            context: [
                'entity_name' => $entityClass,
                'short_name' => $shortName,
                'identifier_fields' => $identifierFields,
                'column_count' => count($identifierFields),
                'referenced_by' => array_map(fn (string $fqcn): string => $this->shortClassName($fqcn), $referencedBy),
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: count($identifierFields) >= 3 ? Severity::critical() : Severity::warning(),
                title: 'Composite Primary Key Detected',
                tags: ['composite-key', 'primary-key', 'architecture'],
            ),
        );
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function createEntityBacktrace(ClassMetadata $classMetadata): ?array
    {
        try {
            $reflectionClass = $classMetadata->getReflectionClass();
            $fileName = $reflectionClass->getFileName();
            $startLine = $reflectionClass->getStartLine();

            if (false === $fileName || false === $startLine) {
                return null;
            }

            return [[
                'file' => $fileName,
                'line' => $startLine,
                'class' => $classMetadata->getName(),
                'function' => '__construct',
                'type' => '::',
            ]];
        } catch (\Exception) {
            return null;
        }
    }
}
