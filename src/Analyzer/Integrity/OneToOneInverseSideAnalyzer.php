<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Concern\ShortClassNameTrait;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Detects bidirectional OneToOne associations where the current entity is the inverse side.
 *
 * The inverse side (mappedBy) of a OneToOne cannot be lazy-loaded because Doctrine
 * has no foreign key to determine the related entity's ID or existence. This forces
 * Doctrine to execute an extra SELECT for every entity loaded, even if the relation
 * is never accessed in code. On a findAll() with N rows, this silently generates N+1 queries.
 *
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/faq.html
 * @see https://github.com/doctrine/orm/issues/4389
 */
class OneToOneInverseSideAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    use ShortClassNameTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly IssueFactoryInterface $issueFactory,
    ) {
    }

    public function analyze(mixed $subject = null): IssueCollection
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
        return 'OneToOne Inverse Side Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects bidirectional OneToOne inverse sides that force Doctrine to execute extra queries on every load';
    }

    /**
     * @return iterable<IntegrityIssue>
     */
    private function analyzeEntity(ClassMetadata $metadata): iterable
    {
        $entityClass = $metadata->getName();

        foreach ($metadata->getAssociationMappings() as $fieldName => $mapping) {
            if (!$this->isOneToOneInverseSide($mapping)) {
                continue;
            }

            $mappedBy = MappingHelper::getString($mapping, 'mappedBy') ?? '';

            $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
            $shortName = $this->shortClassName($entityClass);
            $shortTarget = $this->shortClassName($targetEntity);

            $description = DescriptionHighlighter::highlight(
                "{class}::\${field} is the inverse side of a bidirectional OneToOne with {target}. "
                . "Doctrine cannot lazy-load the inverse side because the foreign key is on {target}'s table. "
                . 'Every time you load a {class}, Doctrine executes an extra SELECT to resolve this relation, '
                . 'even if you never access ${field}. '
                . 'On a findAll() returning N rows, this silently generates N+1 queries.',
                [
                    'class' => $shortName,
                    'field' => $fieldName,
                    'target' => $shortTarget,
                ],
            );

            $issueData = new IssueData(
                type: IssueType::ONE_TO_ONE_INVERSE_SIDE->value,
                title: sprintf('OneToOne Inverse Side: %s::$%s', $shortName, $fieldName),
                description: $description,
                severity: Severity::warning(),
                suggestion: $this->createSuggestion($entityClass, $shortName, $fieldName, $targetEntity, $shortTarget, $mappedBy),
                queries: [],
                backtrace: $this->createEntityBacktrace($metadata),
            );

            /** @var IntegrityIssue $issue */
            $issue = $this->issueFactory->createFromArray($issueData->toArray() + [
                'entity' => $entityClass,
                'field' => $fieldName,
                'target_entity' => $targetEntity,
            ]);

            yield $issue;
        }
    }

    private function isOneToOneInverseSide(array|object $mapping): bool
    {
        // Doctrine 3+/4: mapping is an object, check class name
        if (is_object($mapping)) {
            return str_contains($mapping::class, 'OneToOne') && null !== MappingHelper::getString($mapping, 'mappedBy');
        }

        // Doctrine 2: mapping is an array with 'type' key
        $type = $mapping['type'] ?? null;

        return ClassMetadata::ONE_TO_ONE === $type && !empty($mapping['mappedBy']);
    }

    private function createSuggestion(
        string $entityClass,
        string $shortName,
        string $fieldName,
        string $targetEntity,
        string $shortTarget,
        string $mappedBy,
    ): SuggestionInterface {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/one_to_one_inverse_side',
            context: [
                'entity_class' => $shortName,
                'entity_fqcn' => $entityClass,
                'field_name' => $fieldName,
                'target_class' => $shortTarget,
                'target_fqcn' => $targetEntity,
                'mapped_by' => $mappedBy,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: 'OneToOne Inverse Side Forces Extra Queries',
                tags: ['one-to-one', 'performance', 'n+1', 'lazy-loading'],
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
