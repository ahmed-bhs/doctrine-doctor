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
 * Detects usage of float/double types for monetary values.
 * This is a CRITICAL issue because:
 * - Floating point arithmetic is imprecise (0.1 + 0.2 ≠ 0.3)
 * - Can cause financial discrepancies
 * - Rounding errors accumulate over time
 * - Not suitable for financial calculations
 * Detects fields likely to be monetary based on:
 * - Field names (price, amount, cost, total, balance, etc.)
 * - Column types (float, double with precision/scale)
 * - Context (e-commerce, billing, payment entities)
 */
class FloatForMoneyAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Field names that typically represent monetary values.
     */
    private const array MONEY_FIELD_PATTERNS = [
        'price',
        'amount',
        'cost',
        'total',
        'subtotal',
        'balance',
        'fee',
        'charge',
        'payment',
        'turnover',
        'ca',
        'income',
        'expense',
        'refund',
        'credit',
        'debit',
        'salary',
        'wage',
        'commission',
        'discount',
        'tax',
        'vat',
        'revenue',
        'profit',
        'loss',
    ];

    /**
     * Field names that are NOT monetary (hours, quantities, ratios, coefficients).
     * These are legitimate uses of float/double.
     */
    private const array NON_MONEY_FIELD_PATTERNS = [
        // Time tracking (hours, not money)
        'hours',
        'timeentries',
        'timeentry',
        'duration',
        'elapsed',

        // Quantities and measurements
        'quantity',
        'weight',
        'volume',
        'distance',
        'length',
        'width',
        'height',

        // Ratios, percentages, coefficients (not money)
        'ratio',
        'coefficient',
        'factor',
        'multiplier',
        'rate', // Can be either rate or ratio
        'percentage',
        'percent',

        // Scores and ratings
        'score',
        'rating',
        'rank',
    ];

    /**
     * Entity name patterns that suggest financial/monetary context.
     */
    private const array MONEY_ENTITY_PATTERNS = [
        'invoice',
        'order',
        'payment',
        'transaction',
        'billing',
        'product',
        'cart',
        'checkout',
        'purchase',
        'sale',
        'account',
        'wallet',
        'subscription',
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

                foreach ($classMetadataFactory->getAllMetadata() as $classMetadatum) {
                    if ($classMetadatum->isMappedSuperclass) {
                        continue;
                    }

                    if ($classMetadatum->isEmbeddedClass) {
                        continue;
                    }

                    $entityIssues = $this->analyzeEntity($classMetadatum);

                    Assert::isIterable($entityIssues, '$entityIssues must be iterable');

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

        $issues = [];

        foreach ($classMetadata->fieldMappings as $fieldName => $mapping) {
            // Normalize mapping to array (Doctrine ORM 3.x returns FieldMapping objects)
            if (is_object($mapping)) {
                $mapping = (array) $mapping;
            }

            if ($this->isFloatUsedForMoney($classMetadata, $fieldName, $mapping)) {
                $issues[] = $this->createFloatForMoneyIssue($classMetadata, $fieldName, $mapping);
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed>|object $mapping
     */
    private function isFloatUsedForMoney(ClassMetadata $classMetadata, string $fieldName, array|object $mapping): bool
    {
        $type = MappingHelper::getString($mapping, 'type');

        // Check if it's a float/double type
        if (!in_array($type, ['float', 'double'], true)) {
            return false;
        }

        // FIRST: Check if it's explicitly NOT money (hours, quantities, etc.)
        if ($this->isNonMoneyField($fieldName)) {
            return false;
        }

        // Check if field name suggests money
        if ($this->isMoneyField($fieldName)) {
            return true;
        }

        // Check if entity context suggests money
        // Even generic names like "value" or "amount" are suspicious in money entities
        return $this->isMoneyEntity($classMetadata->getName()) && $this->isGenericNumberField($fieldName);
    }

    private function isMoneyField(string $fieldName): bool
    {
        $fieldLower = strtolower($fieldName);
        return array_any(self::MONEY_FIELD_PATTERNS, fn ($pattern) => str_contains($fieldLower, (string) $pattern));
    }

    private function isNonMoneyField(string $fieldName): bool
    {
        $fieldLower = strtolower($fieldName);
        return array_any(self::NON_MONEY_FIELD_PATTERNS, fn ($pattern) => str_contains($fieldLower, (string) $pattern));
    }

    private function isMoneyEntity(string $className): bool
    {
        $classLower = strtolower($className);
        return array_any(self::MONEY_ENTITY_PATTERNS, fn ($pattern) => str_contains($classLower, (string) $pattern));
    }

    private function isGenericNumberField(string $fieldName): bool
    {
        $genericNames = ['value', 'amount', 'sum', 'total'];

        return in_array(strtolower($fieldName), $genericNames, true);
    }

    /**
     * @param array<string, mixed>|object $mapping
     */
    private function createFloatForMoneyIssue(
        ClassMetadata $classMetadata,
        string $fieldName,
        array|object $mapping,
    ): IssueInterface {
        $className      = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $description = sprintf(
            'Entity %s::$%s uses float/double type for what appears to be a monetary value. ' .
            'Floating point arithmetic is imprecise and can cause financial discrepancies. ' .
            'Use decimal type with string property or a Money library instead.',
            $shortClassName,
            $fieldName,
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'float_for_money',
            'title'       => sprintf('Float Used for Money: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'critical',
            'category'    => 'integrity',
            'suggestion'  => $this->createMoneyHandlingSuggestion($shortClassName, $fieldName),
            'backtrace'   => [
                'entity'       => $className,
                'field'        => $fieldName,
                'current_type' => MappingHelper::getString($mapping, 'type'),
            ],
        ]);
    }

    private function createMoneyHandlingSuggestion(string $className, string $fieldName): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/float_for_money',
            context: [
                'entity_class' => $className,
                'field_name'   => $fieldName,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::critical(),
                title: sprintf('Float Used for Money: %s::$%s', $className, $fieldName),
                tags: ['critical', 'money', 'float', 'precision'],
            ),
        );
    }
}
