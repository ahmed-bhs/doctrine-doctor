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
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Entities deleted "softly" (a deletedAt date or an isDeleted flag) while no SQL filter is enabled to hide those
 * rows: every query, repository method or relation that forgets the condition shows deleted records.
 */
class MissingSoftDeleteFilterAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;

    // "Archived" rows stay valid (shown in back offices on purpose): only deletions are checked.
    private const array DATE_FIELDS = ['deletedat', 'removedat'];

    private const array FLAG_FIELDS = ['deleted', 'isdeleted', 'removed', 'isremoved'];

    private const string FILTER_NAME = '/soft|delet|remov|trash/i';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(function () {
            if ($this->hasSoftDeleteFilter()) {
                return;
            }

            foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
                if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                    continue;
                }

                $field = $this->softDeleteField($metadata);
                if (null !== $field) {
                    yield $this->createIssue($metadata, $field);
                }
            }
        });
    }

    public function getName(): string
    {
        return 'Missing Soft Delete Filter Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects soft-deleted entities without an enabled SQL filter that hides deleted rows';
    }

    private function hasSoftDeleteFilter(): bool
    {
        return array_any($this->entityManager->getFilters()->getEnabledFilters(), fn ($filter, $name) => 1 === preg_match(self::FILTER_NAME, $name . ' ' . $filter::class));
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function softDeleteField(ClassMetadata $metadata): ?string
    {
        foreach ($metadata->fieldMappings as $field => $mapping) {
            $name = strtolower(str_replace('_', '', (string) $field));
            $type = (string) MappingHelper::getString($mapping, 'type');

            if (\in_array($name, self::DATE_FIELDS, true) && str_starts_with($type, 'datetime')) {
                return (string) $field;
            }
            if (\in_array($name, self::FLAG_FIELDS, true) && 'boolean' === $type) {
                return (string) $field;
            }
        }

        return null;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function createIssue(ClassMetadata $metadata, string $field): IntegrityIssue
    {
        $shortName = $metadata->getReflectionClass()->getShortName();
        $column = $metadata->getColumnName($field);

        return new IntegrityIssue([
            'type' => IssueType::SOFT_DELETE_WITHOUT_FILTER->value,
            'title' => sprintf('Soft Delete Without Filter: %s::$%s', $shortName, $field),
            'description' => sprintf(
                '%s is deleted by setting %s, but no SQL filter hides the deleted rows. Every query, repository method or relation that forgets the condition on %s shows them again.',
                $shortName,
                $field,
                $column,
            ),
            'severity' => Severity::warning(),
            'suggestion' => $this->suggestionFactory->createFromTemplate(
                templateName: 'Integrity/soft_delete_without_filter',
                context: ['entity' => $shortName, 'field' => $field, 'column' => $column],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::integrity(),
                    severity: Severity::warning(),
                    title: 'Hide deleted rows with a Doctrine filter',
                    tags: ['soft-delete', 'filter'],
                ),
            ),
            'queries' => [],
            'entity' => $metadata->getName(),
            'field' => $field,
        ]);
    }
}
