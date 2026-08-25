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
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Detects primary key columns declared as nullable.
 *
 * A primary key can never hold NULL, so the flag is always meaningless. Doctrine
 * ORM 3.6 deprecated it (doctrine/orm#12126) and it is scheduled for removal, so
 * the mapping also blocks the upgrade to the next major version.
 */
class NullablePrimaryKeyAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;
    use ShortClassNameTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(function () {
            try {
                /** @var array<ClassMetadata<object>> $allMetadata */
                $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
            } catch (\Throwable) {
                return;
            }

            foreach ($allMetadata as $metadata) {
                if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                    continue;
                }

                yield from $this->analyzeEntity($metadata);
            }
        });
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @return iterable<IntegrityIssue>
     */
    private function analyzeEntity(ClassMetadata $metadata): iterable
    {
        foreach ($metadata->identifier as $fieldName) {
            $mapping = $metadata->fieldMappings[$fieldName] ?? null;

            if (null === $mapping) {
                continue;
            }

            $nullable = $mapping['nullable'] ?? $mapping->nullable ?? false;

            if (!$nullable) {
                continue;
            }

            $entityClass = $this->shortClassName($metadata->getName());

            $description = DescriptionHighlighter::highlight(
                'Primary key column {field} on {entity} is mapped as nullable. '
                . 'A primary key can never hold NULL, so the flag has no effect on the schema. '
                . 'Doctrine ORM 3.6 deprecated it and the next major version rejects it, '
                . 'so this mapping blocks the upgrade.',
                [
                    'field'  => $fieldName,
                    'entity' => $entityClass,
                ],
            );

            yield new IntegrityIssue(new IssueData(
                type: IssueType::NULLABLE_PRIMARY_KEY->value,
                title: sprintf('Nullable primary key: %s::$%s', $entityClass, $fieldName),
                description: $description,
                severity: Severity::warning(),
                suggestion: $this->suggestionFactory->createFromTemplate(
                    templateName: 'Integrity/nullable_primary_key',
                    context: [
                        'entity_class' => $entityClass,
                        'field_name'   => $fieldName,
                    ],
                    suggestionMetadata: new SuggestionMetadata(
                        type: SuggestionType::integrity(),
                        severity: Severity::warning(),
                        title: 'Remove nullable from the primary key',
                        tags: ['deprecation', 'mapping'],
                    ),
                ),
            )->toArray());
        }
    }
}
