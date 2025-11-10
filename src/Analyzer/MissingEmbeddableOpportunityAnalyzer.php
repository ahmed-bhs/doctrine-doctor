<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
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
class MissingEmbeddableOpportunityAnalyzer implements AnalyzerInterface
{
    /**
     * Patterns to detect embeddable opportunities.
     * Each pattern defines field names that commonly appear together.
     * @var array<string, array{required: array<string>, optional: array<string>}>
     */
    private const EMBEDDABLE_PATTERNS = [
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
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private IssueFactoryInterface $issueFactory,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                $classMetadataFactory = $this->entityManager->getMetadataFactory();

                foreach ($classMetadataFactory->getAllMetadata() as $classMetadatum) {
                    if ($classMetadatum->isMappedSuperclass) {
                        continue;
                    }

                    if ($classMetadatum->isEmbeddedClass) {
                        continue;
                    }

                    $entityIssues = $this->analyzeEntity($classMetadatum);

                    assert(is_iterable($entityIssues), '$entityIssues must be iterable');

                    foreach ($entityIssues as $entityIssue) {
                        yield $entityIssue;
                    }
                }
            },
        );
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     * @return array<\AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {
        $issues       = [];
        $fieldNames   = array_keys($classMetadata->fieldMappings);
        $fieldLowerMap = array_combine(
            array_map(function ($fieldName) {
                return strtolower($fieldName);
            }, $fieldNames),
            $fieldNames,
        );

        foreach (self::EMBEDDABLE_PATTERNS as $embeddableName => $pattern) {
            $matchedFields = $this->findMatchingFields($fieldLowerMap, $pattern);

            if ([] !== $matchedFields) {
                $issues[] = $this->createMissingEmbeddableIssue(
                    $classMetadata,
                    $embeddableName,
                    $matchedFields,
                );
            }
        }

        return $issues;
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
        assert(is_iterable($requiredFields), '$requiredFields must be iterable');

        foreach ($requiredFields as $requiredField) {
            $requiredFieldLower = strtolower($requiredField);

            if (!isset($fieldLowerMap[$requiredFieldLower])) {
                // Required field not found, pattern doesn't match
                return [];
            }

            $matchedFields[] = $fieldLowerMap[$requiredFieldLower];
        }

        // Add optional fields if they exist
        assert(is_iterable($optionalFields), '$optionalFields must be iterable');

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
            'type'        => 'missing_embeddable_opportunity',
            'title'       => sprintf('Missing %s Embeddable: %s', $embeddableName, $shortClassName),
            'description' => $description,
            'severity'    => 'info',
            'category'    => 'code_quality',
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
            templateName: 'missing_embeddable_opportunity',
            context: [
                'entity_class'     => $className,
                'embeddable_name'  => $embeddableName,
                'fields'           => $fields,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::codeQuality(),
                severity: Severity::info(),
                title: sprintf('Refactor to %s Embeddable', $embeddableName),
                tags: ['embeddable', 'value-object', 'ddd', 'refactoring'],
            ),
        );
    }
}
