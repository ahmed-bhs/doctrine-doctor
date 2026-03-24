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
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

class JoinColumnNonPrimaryKeyAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    use ShortClassNameTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            function () {
                try {
                    $metadataFactory = $this->entityManager->getMetadataFactory();
                    $allMetadata = $metadataFactory->getAllMetadata();

                    foreach ($allMetadata as $metadata) {
                        $issues = $this->analyzeEntity($metadata);

                        foreach ($issues as $issue) {
                            yield $issue;
                        }
                    }
                } catch (\Throwable $throwable) {
                    $this->logger?->error('JoinColumnNonPrimaryKeyAnalyzer failed', [
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
        $issues = [];

        foreach ($classMetadata->getAssociationMappings() as $fieldName => $mapping) {
            if (!$this->isToOneAssociation($mapping)) {
                continue;
            }

            $targetEntityClass = MappingHelper::getString($mapping, 'targetEntity');

            if (null === $targetEntityClass) {
                continue;
            }

            try {
                $targetMetadata = $this->entityManager->getClassMetadata($targetEntityClass);
            } catch (\Throwable) {
                continue;
            }

            $referencedColumnNames = $this->getReferencedColumnNames($mapping);

            if ([] === $referencedColumnNames) {
                continue;
            }

            $primaryKeyColumns = $targetMetadata->getIdentifierColumnNames();
            $invalidColumns = array_values(array_diff($referencedColumnNames, $primaryKeyColumns));

            if ([] === $invalidColumns) {
                continue;
            }

            $issues[] = $this->createIssue(
                $classMetadata->getName(),
                $fieldName,
                $targetEntityClass,
                $invalidColumns,
                $primaryKeyColumns,
            );
        }

        return $issues;
    }

    /**
     * @param array<string, mixed>|object $mapping
     */
    private function isToOneAssociation(array|object $mapping): bool
    {
        $type = MappingHelper::getProperty($mapping, 'type');

        if (null === $type) {
            if ($mapping instanceof \Doctrine\ORM\Mapping\ManyToOneAssociationMapping) {
                return true;
            }

            if ($mapping instanceof \Doctrine\ORM\Mapping\OneToOneAssociationMapping) {
                return true;
            }

            return false;
        }

        return ClassMetadata::MANY_TO_ONE === $type || ClassMetadata::ONE_TO_ONE === $type;
    }

    /**
     * @param array<string, mixed>|object $mapping
     * @return array<string>
     */
    private function getReferencedColumnNames(array|object $mapping): array
    {
        $joinColumns = MappingHelper::getArray($mapping, 'joinColumns');

        if (null === $joinColumns || [] === $joinColumns) {
            return [];
        }

        $names = [];

        foreach ($joinColumns as $joinColumn) {
            $name = MappingHelper::getString($joinColumn, 'referencedColumnName');

            if (null !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string> $invalidColumns
     * @param array<string> $primaryKeyColumns
     */
    private function createIssue(
        string $entityClass,
        string $fieldName,
        string $targetEntityClass,
        array $invalidColumns,
        array $primaryKeyColumns,
    ): IssueInterface {
        $shortEntity = $this->shortClassName($entityClass);
        $shortTarget = $this->shortClassName($targetEntityClass);
        $invalidColumnsStr = implode(', ', $invalidColumns);

        $description = sprintf(
            'Association "%s::%s" references column(s) "%s" on target entity "%s", '
            . 'but %s not part of the primary key (primary key: %s). '
            . 'Doctrine will treat the referenced column value as the identifier for lazy-loading proxies, '
            . 'which can lead to incorrect data being loaded. '
            . 'This is a known Doctrine ORM limitation that cannot be validated at runtime.',
            $shortEntity,
            $fieldName,
            $invalidColumnsStr,
            $shortTarget,
            count($invalidColumns) > 1 ? 'these are' : 'this is',
            implode(', ', $primaryKeyColumns),
        );

        $suggestion = $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/code_suggestion',
            context: [
                'description' => sprintf(
                    'Refactor the association to reference the primary key of %s instead of "%s", '
                    . 'or use the Doctrine Validate Schema command to detect this at build time.',
                    $shortTarget,
                    $invalidColumnsStr,
                ),
                'code' => $this->buildSuggestionCode($shortEntity, $fieldName, $shortTarget, $primaryKeyColumns),
                'file_path' => $this->resolveFilePath($entityClass, $fieldName),
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::critical(),
                title: 'Join Column References Non-Primary Key',
                tags: ['integrity', 'orm', 'lazy-loading'],
            ),
        );

        $issueData = new IssueData(
            type: IssueType::JOIN_COLUMN_NON_PRIMARY_KEY->value,
            title: sprintf('Join column references non-primary key in %s::%s', $shortEntity, $fieldName),
            description: $description,
            severity: Severity::critical(),
            suggestion: $suggestion,
        );

        return $this->issueFactory->create($issueData);
    }

    /**
     * @param array<string> $primaryKeyColumns
     */
    private function buildSuggestionCode(
        string $shortEntity,
        string $fieldName,
        string $shortTarget,
        array $primaryKeyColumns,
    ): string {
        $code = "// In {$shortEntity}:\n\n";
        $code .= "// PROBLEMATIC - references non-primary key:\n";
        $code .= "// #[ORM\\JoinColumn(name: '{$fieldName}_id', referencedColumnName: '<non_pk_column>')]\n";
        $code .= "// private {$shortTarget} \${$fieldName};\n\n";
        $code .= "// CORRECT - reference the primary key:\n";
        $code .= "#[ORM\\ManyToOne(targetEntity: {$shortTarget}::class)]\n";

        if (1 === count($primaryKeyColumns)) {
            $code .= "#[ORM\\JoinColumn(name: '{$fieldName}_id', referencedColumnName: '{$primaryKeyColumns[0]}')]\n";
        } else {
            $code .= "#[ORM\\JoinColumns([\n";
            foreach ($primaryKeyColumns as $pkCol) {
                $code .= "    new ORM\\JoinColumn(name: '{$fieldName}_{$pkCol}', referencedColumnName: '{$pkCol}'),\n";
            }
            $code .= "])]\n";
        }

        $code .= "private {$shortTarget} \${$fieldName};";

        return $code;
    }

    private function resolveFilePath(string $entityClass, string $fieldName): string
    {
        try {
            Assert::classExists($entityClass);
            $reflectionClass = new \ReflectionClass($entityClass);
            $fileName = $reflectionClass->getFileName();

            if (false === $fileName) {
                return 'unknown';
            }

            if ($reflectionClass->hasProperty($fieldName)) {
                $property = $reflectionClass->getProperty($fieldName);
                $line = $property->getDeclaringClass()->getStartLine();

                if (false !== $line) {
                    return sprintf('%s:%d', $fileName, $line);
                }
            }

            return $fileName;
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
