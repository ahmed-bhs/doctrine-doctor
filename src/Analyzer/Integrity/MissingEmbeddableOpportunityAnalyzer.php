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
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Detects groups of properties that should be refactored into Doctrine Embeddables.
 * Embeddables provide better cohesion and reusability by grouping related properties
 * into Value Objects without creating separate entities (no identity, no extra joins).
 * Common patterns detected:
 * - Money: amount + currency → Money embeddable
 * - Address: street, city, zipCode, country → Address embeddable
 * - PersonName: firstName, lastName → PersonName embeddable
 * - Coordinates: latitude, longitude → Coordinates embeddable
 * - DateRange: startDate, endDate → DateRange embeddable
 * - Email: email + emailVerified → Email embeddable
 * - Phone: phoneNumber + phoneCountryCode → Phone embeddable
 * Benefits:
 * - Better Domain-Driven Design (Value Objects)
 * - Code reusability across entities
 * - Encapsulation of related logic
 * - Type safety and immutability
 * - No extra database joins (embedded in same table)
 */
class MissingEmbeddableOpportunityAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;

    /**
     * Patterns to detect embeddable opportunities.
     * Each pattern defines field names that commonly appear together.
     * @var array<string, array{required: array<string>, optional: array<string>}>
     */
    private const array EMBEDDABLE_PATTERNS = [
        'Money' => [
            'required' => ['amount', 'currency'],
            'optional' => [],
        ],
        'Address' => [
            'required' => ['street', 'city'],
            'optional' => ['zipCode', 'zipcode', 'postalCode', 'postalcode', 'country', 'state', 'region'],
        ],
        'PersonName' => [
            'required' => ['firstName', 'lastname'],
            'optional' => ['middleName', 'title', 'suffix'],
        ],
        'Coordinates' => [
            'required' => ['latitude', 'longitude'],
            'optional' => ['altitude', 'precision'],
        ],
        'DateRange' => [
            'required' => ['startDate', 'endDate'],
            'optional' => ['startTime', 'endTime'],
        ],
        'Email' => [
            'required' => ['email'],
            'optional' => ['emailVerified', 'emailVerifiedAt'],
        ],
        'Phone' => [
            'required' => ['phone'],
            'optional' => ['phoneCountryCode', 'phoneVerified', 'phoneType'],
        ],
        'Dimensions' => [
            'required' => ['width', 'height'],
            'optional' => ['depth', 'length', 'weight'],
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly int $minEntities = 2,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                $classMetadataFactory = $this->entityManager->getMetadataFactory();

                /** @var array<string, array<array{metadata: ClassMetadata<object>, fields: array<string>}>> $matchesByPattern */
                $matchesByPattern = [];

                foreach ($classMetadataFactory->getAllMetadata() as $classMetadatum) {
                    if ($classMetadatum->isMappedSuperclass || $classMetadatum->isEmbeddedClass) {
                        continue;
                    }

                    $fieldNames   = array_keys($classMetadatum->fieldMappings);
                    $fieldLowerMap = array_combine(
                        array_map(strtolower(...), $fieldNames),
                        $fieldNames,
                    );

                    foreach (self::EMBEDDABLE_PATTERNS as $embeddableName => $pattern) {
                        $matchedFields = $this->findMatchingFields($fieldLowerMap, $pattern);

                        if ([] !== $matchedFields) {
                            $matchesByPattern[$embeddableName][] = [
                                'metadata' => $classMetadatum,
                                'fields' => $matchedFields,
                            ];
                        }
                    }
                }

                foreach ($matchesByPattern as $embeddableName => $matches) {
                    if (\count($matches) < $this->minEntities) {
                        continue;
                    }

                    foreach ($matches as $match) {
                        yield $this->createMissingEmbeddableIssue(
                            $match['metadata'],
                            $embeddableName,
                            $match['fields'],
                        );
                    }
                }
            },
        );
    }

    /**
     * Find fields matching a pattern.
     * @param array<string, string> $fieldLowerMap
     * @param array<string, array<string>> $pattern
     * @return array<string>
     */
    private function findMatchingFields(array $fieldLowerMap, array $pattern): array
    {
        $matchedFields  = [];
        $requiredFields = $pattern['required'];
        $optionalFields = $pattern['optional'] ?? [];

        // Check if all required fields exist
        foreach ($requiredFields as $requiredField) {
            $requiredFieldLower = strtolower($requiredField);

            if (!isset($fieldLowerMap[$requiredFieldLower])) {
                // Required field not found, pattern doesn't match
                return [];
            }

            $matchedFields[] = $fieldLowerMap[$requiredFieldLower];
        }

        // Add optional fields if they exist
        foreach ($optionalFields as $optionalField) {
            $optionalFieldLower = strtolower($optionalField);

            if (isset($fieldLowerMap[$optionalFieldLower])) {
                $matchedFields[] = $fieldLowerMap[$optionalFieldLower];
            }
        }

        return $matchedFields;
    }

    /**
     * @param array<string> $fields
     */
    private function createMissingEmbeddableIssue(
        ClassMetadata $classMetadata,
        string $embeddableName,
        array $fields,
    ): IssueInterface {
        $className      = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $fieldsList = implode(', ', array_map(fn (string $field): string => '$' . $field, $fields));

        $description = sprintf(
            'Entity %s has properties (%s) that should be refactored into a %s Embeddable. ' .
            'Embeddables provide better cohesion by grouping related properties into Value Objects ' .
            'without creating separate entities (no identity, no extra joins). ' .
            'This improves Domain-Driven Design, code reusability, and type safety.',
            $shortClassName,
            $fieldsList,
            $embeddableName,
        );

        return $this->issueFactory->createFromArray([
            'type' => IssueType::MISSING_EMBEDDABLE_OPPORTUNITY->value,
            'title'       => sprintf('Missing %s Embeddable: %s', $embeddableName, $shortClassName),
            'description' => $description,
            'severity'    => 'info',
            'category'    => 'integrity',
            'suggestion'  => $this->createEmbeddableSuggestion($shortClassName, $embeddableName, $fields),
            'backtrace'   => [
                'entity'           => $className,
                'embeddable_type'  => $embeddableName,
                'fields'           => $fields,
            ],
        ]);
    }

    /**
     * @param array<string> $fields
     */
    private function createEmbeddableSuggestion(
        string $className,
        string $embeddableName,
        array $fields,
    ): SuggestionInterface {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/missing_embeddable_opportunity',
            context: [
                'entity_class'     => $className,
                'embeddable_name'  => $embeddableName,
                'fields'           => $fields,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::info(),
                title: sprintf('Refactor to %s Embeddable', $embeddableName),
                tags: ['embeddable', 'value-object', 'ddd', 'refactoring'],
            ),
        );
    }
}
