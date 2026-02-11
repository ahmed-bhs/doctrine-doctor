<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Webmozart\Assert\Assert;

/**
 * Detects usage of float/double types in Money Embeddables.
 * This is a CRITICAL issue specific to Embeddables because:
 * - Floating point arithmetic is imprecise (0.1 + 0.2 ≠ 0.3)
 * - Can cause financial discrepancies in calculations
 * - Rounding errors accumulate over time
 * - Not suitable for monetary calculations
 * - Money Value Objects should use integer (cents) or string (decimal)
 * Best practices for Money embeddables:
 * - Use integer type to store smallest unit (cents, pennies, etc.)
 * - Or use decimal type with string property
 * - Implement arithmetic methods (add, subtract, multiply) on the Value Object
 * - Handle currency conversion properly
 * Example from the article:
 * - Money class with integer $amount (in cents)
 * - string $currency (ISO code like "USD")
 * - Methods: add(), subtract(), equals(), isGreaterThan()
 */
class FloatInMoneyEmbeddableAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Field names that suggest money/monetary values in embeddables.
     */
    private const array MONEY_FIELD_PATTERNS = [
        'amount',
        'value',
        'price',
        'cost',
        'total',
        'sum',
        'balance',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactory $suggestionFactory,
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
                $processedEmbeddables = [];

                foreach ($classMetadataFactory->getAllMetadata() as $classMetadatum) {
                    // Check if this is an embeddable class directly
                    if ($classMetadatum->isEmbeddedClass) {
                        if (!in_array($classMetadatum->getName(), $processedEmbeddables, true)) {
                            $processedEmbeddables[] = $classMetadatum->getName();

                            $entityIssues = $this->analyzeEmbeddable($classMetadatum);

                            Assert::isIterable($entityIssues, '$entityIssues must be iterable');

                            foreach ($entityIssues as $entityIssue) {
                                yield $entityIssue;
                            }
                        }
                        continue;
                    }

                    // For entities, check their embedded classes
                    if (!empty($classMetadatum->embeddedClasses)) {
                        foreach ($classMetadatum->embeddedClasses as $embeddedClass) {
                            $embeddableClassName = $embeddedClass->class ?? $embeddedClass['class'] ?? null;

                            if (is_string($embeddableClassName) && !in_array($embeddableClassName, $processedEmbeddables, true)) {
                                $processedEmbeddables[] = $embeddableClassName;

                                // Get metadata for the embeddable class
                                try {
                                    /** @var class-string $embeddableClassName */
                                    $embeddableMetadata = $classMetadataFactory->getMetadataFor($embeddableClassName);
                                    $embeddableIssues = $this->analyzeEmbeddable($embeddableMetadata);

                                    Assert::isIterable($embeddableIssues, '$embeddableIssues must be iterable');

                                    foreach ($embeddableIssues as $embeddableIssue) {
                                        yield $embeddableIssue;
                                    }
                                } catch (\Throwable) {
                                    // Skip if metadata cannot be loaded
                                    continue;
                                }
                            }
                        }
                    }
                }
            },
        );
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     * @return array<\AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function analyzeEmbeddable(ClassMetadata $classMetadata): array
    {
        $issues = [];

        // Check if this is a Money-like embeddable
        if (!$this->isMoneyEmbeddable($classMetadata)) {
            return $issues;
        }

        // Check for float/double fields
        foreach ($classMetadata->fieldMappings as $fieldName => $mapping) {
            $type = MappingHelper::getString($mapping, 'type');

            if (in_array($type, ['float', 'double'], true) && $this->isMoneyField($fieldName)) {
                $issues[] = $this->createFloatInMoneyIssue($classMetadata, $fieldName, $mapping);
            }
        }

        return $issues;
    }

    /**
     * Check if embeddable is likely a Money value object.
     * @param ClassMetadata<object> $classMetadata
     */
    private function isMoneyEmbeddable(ClassMetadata $classMetadata): bool
    {
        $className = $classMetadata->getName();
        $fieldNames = array_keys($classMetadata->fieldMappings);

        // Check if class name suggests money
        if (str_contains(strtolower($className), 'money')
            || str_contains(strtolower($className), 'price')
            || str_contains(strtolower($className), 'amount')) {
            return true;
        }

        // Check if it has currency field (strong indicator)
        Assert::isIterable($fieldNames, '$fieldNames must be iterable');
        return array_any($fieldNames, fn ($fieldName) => str_contains(strtolower((string) $fieldName), 'currency'));
    }

    private function isMoneyField(string $fieldName): bool
    {
        $fieldLower = strtolower($fieldName);
        return array_any(self::MONEY_FIELD_PATTERNS, fn ($pattern) => str_contains($fieldLower, (string) $pattern));
    }

    /**
     * @param array<string, mixed>|object $mapping
     */
    private function createFloatInMoneyIssue(
        ClassMetadata $classMetadata,
        string $fieldName,
        array|object $mapping,
    ): IssueInterface {
        $className      = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $description = sprintf(
            'Money Embeddable %s::$%s uses float/double type which is CRITICAL for financial data. ' .
            'Floating point arithmetic is imprecise and causes financial discrepancies. ' .
            "\n\n" .
            'Best practices for Money Value Objects:' .
            "\n" .
            '1. Use integer type to store smallest unit (e.g., cents: 1999 for $19.99)' .
            "\n" .
            '2. Or use decimal type mapped to string property' .
            "\n" .
            '3. Implement arithmetic methods (add, subtract, multiply) in the Value Object' .
            "\n" .
            '4. Make the embeddable immutable (return new instances)',
            $shortClassName,
            $fieldName,
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'float_in_money_embeddable',
            'title'       => sprintf('Float in Money Embeddable: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'critical',
            'category'    => 'integrity',
            'suggestion'  => $this->createIntegerMoneySuggestion($shortClassName, $fieldName),
            'backtrace'   => [
                'entity'       => $className,
                'field'        => $fieldName,
                'current_type' => MappingHelper::getString($mapping, 'type'),
            ],
        ]);
    }

    private function createIntegerMoneySuggestion(string $className, string $fieldName): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/float_in_money_embeddable',
            context: [
                'embeddable_class' => $className,
                'field_name'       => $fieldName,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::critical(),
                title: sprintf('Use Integer for Money: %s::$%s', $className, $fieldName),
                tags: ['critical', 'money', 'embeddable', 'float', 'precision'],
            ),
        );
    }
}
