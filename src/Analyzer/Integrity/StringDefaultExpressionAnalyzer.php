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
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Detects date and time columns whose default value is a raw SQL string such as
 * CURRENT_TIMESTAMP.
 *
 * Doctrine ORM 3.6 deprecated raw string defaults on temporal columns
 * (doctrine/orm#12252) in favour of the DBAL DefaultExpression value objects
 * introduced in DBAL 4.4 (doctrine/dbal#7195). ORM 4.0 drops the string form,
 * so the mapping blocks the upgrade.
 *
 * The check mirrors SchemaTool: only temporal types are considered, and only when
 * the default matches the current platform's own current-date/time SQL.
 */
class StringDefaultExpressionAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;
    use ShortClassNameTrait;

    private const string EXPRESSION_CLASS = \Doctrine\DBAL\Schema\DefaultExpression::class;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(function () {
            if (!interface_exists(self::EXPRESSION_CLASS)) {
                return;
            }

            try {
                /** @var array<ClassMetadata<object>> $allMetadata */
                $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
                $platform = $this->entityManager->getConnection()->getDatabasePlatform();
            } catch (\Throwable) {
                return;
            }

            $replacements = [
                $platform->getCurrentTimestampSQL() => [
                    'expression' => 'CurrentTimestamp',
                    'types'      => [
                        Types::DATETIME_MUTABLE,
                        Types::DATETIME_IMMUTABLE,
                        Types::DATETIMETZ_MUTABLE,
                        Types::DATETIMETZ_IMMUTABLE,
                    ],
                ],
                $platform->getCurrentTimeSQL() => [
                    'expression' => 'CurrentTime',
                    'types'      => [Types::TIME_MUTABLE, Types::TIME_IMMUTABLE],
                ],
                $platform->getCurrentDateSQL() => [
                    'expression' => 'CurrentDate',
                    'types'      => [Types::DATE_MUTABLE, Types::DATE_IMMUTABLE],
                ],
            ];

            foreach ($allMetadata as $metadata) {
                if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                    continue;
                }

                yield from $this->analyzeEntity($metadata, $replacements);
            }
        });
    }

    /**
     * @param ClassMetadata<object>                                       $metadata
     * @param array<string, array{expression: string, types: list<string>}> $replacements
     * @return iterable<IntegrityIssue>
     */
    private function analyzeEntity(ClassMetadata $metadata, array $replacements): iterable
    {
        foreach ($metadata->fieldMappings as $fieldName => $mapping) {
            $options = $mapping['options'] ?? $mapping->options ?? [];
            $default = $options['default'] ?? null;

            if (!is_string($default)) {
                continue;
            }

            $type = $mapping['type'] ?? $mapping->type ?? null;

            if (!is_string($type)) {
                continue;
            }

            foreach ($replacements as $sql => $replacement) {
                if ($default !== $sql || !in_array($type, $replacement['types'], true)) {
                    continue;
                }

                yield $this->createIssue(
                    $metadata,
                    (string) $fieldName,
                    $default,
                    $replacement['expression'],
                );
            }
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function createIssue(
        ClassMetadata $metadata,
        string $fieldName,
        string $default,
        string $expression,
    ): IntegrityIssue {
        $entityClass = $this->shortClassName($metadata->getName());

        $description = DescriptionHighlighter::highlight(
            'Column {field} on {entity} uses the raw string {default} as its default value. '
            . 'Doctrine ORM 3.6 deprecated string defaults on temporal columns and ORM 4.0 '
            . 'removes them. Pass a {expression} instance instead.',
            [
                'field'      => $fieldName,
                'entity'     => $entityClass,
                'default'    => $default,
                'expression' => $expression,
            ],
        );

        return new IntegrityIssue(new IssueData(
            type: IssueType::STRING_DEFAULT_EXPRESSION->value,
            title: sprintf('Deprecated string default: %s::$%s', $entityClass, $fieldName),
            description: $description,
            severity: Severity::warning(),
            suggestion: $this->suggestionFactory->createFromTemplate(
                templateName: 'Integrity/string_default_expression',
                context: [
                    'entity_class'    => $entityClass,
                    'field_name'      => $fieldName,
                    'default_value'   => $default,
                    'expression_name' => $expression,
                ],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::integrity(),
                    severity: Severity::warning(),
                    title: 'Use a DefaultExpression instance',
                    tags: ['deprecation', 'mapping'],
                ),
            ),
        )->toArray());
    }
}
