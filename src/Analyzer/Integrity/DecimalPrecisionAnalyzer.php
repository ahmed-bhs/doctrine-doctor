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
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Webmozart\Assert\Assert;

/**
 * Analyzes decimal column configurations for precision and scale issues.
 * Checks for:
 * - Missing precision/scale (defaults may not be suitable)
 * - Insufficient precision for monetary values
 * - Scale mismatch with currency requirements (usually 2 for most currencies)
 * - Excessive precision causing storage waste
 */
class DecimalPrecisionAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Recommended configurations for common decimal use cases.
     */
    private const array RECOMMENDED_CONFIGS = [
        'money'      => ['precision' => 19, 'scale' => 4], // Supports multi-currency with high precision
        'percentage' => ['precision' => 5, 'scale' => 2], // 0.00 to 100.00
        'quantity'   => ['precision' => 10, 'scale' => 2], // Product quantities
        'weight'     => ['precision' => 10, 'scale' => 3], // Weights in kg/lbs
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

    public function getName(): string
    {
        return 'Decimal Precision Analyzer';
    }

    public function getCategory(): string
    {
        return 'configuration';
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

            if (($mapping['type'] ?? null) !== 'decimal') {
                continue;
            }

            // Check for missing precision/scale
            if (!isset($mapping['precision']) || !isset($mapping['scale'])) {
                $issues[] = $this->createMissingPrecisionIssue($classMetadata, $fieldName);
                continue;
            }

            // Check for inappropriate precision/scale
            $issue = $this->checkPrecisionAppropriate($classMetadata, $fieldName, $mapping);

            if ($issue instanceof IssueInterface) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    private function createMissingPrecisionIssue(
        ClassMetadata $classMetadata,
        string $fieldName,
    ): IssueInterface {
        $className      = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $description = sprintf(
            'Entity %s::$%s uses decimal type without explicit precision/scale. ' .
            'This relies on database defaults which vary between vendors and may not be suitable for your use case.',
            $shortClassName,
            $fieldName,
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'decimal_missing_precision',
            'title'       => sprintf('Missing Decimal Precision: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'warning',
            'category'    => 'configuration',
            'suggestion'  => $this->createPrecisionSuggestion($fieldName),
        ]);
    }

    private function checkPrecisionAppropriate(
        ClassMetadata $classMetadata,
        string $fieldName,
        array $mapping,
    ): ?IssueInterface {
        $precision = $mapping['precision'];
        $scale     = $mapping['scale'];
        $classMetadata->getName();

        // Check if it looks like a money field
        if ($this->isMoneyField($fieldName)) {
            // Money fields should have at least precision 10, scale 2
            if ($precision < 10 || $scale < 2) {
                return $this->createInsufficientPrecisionIssue(
                    $classMetadata,
                    $fieldName,
                    $precision,
                    $scale,
                    'money',
                );
            }

            // Warn if scale is not 2 or 4 (unusual for money)
            if (!in_array($scale, [2, 4], true)) {
                return $this->createUnusualScaleIssue(
                    $classMetadata,
                    $fieldName,
                    $scale,
                    'money',
                );
            }
        }

        // Check for percentage fields
        if ($this->isPercentageField($fieldName) && ($precision < 5 || $scale < 2)) {
            return $this->createInsufficientPrecisionIssue(
                $classMetadata,
                $fieldName,
                $precision,
                $scale,
                'percentage',
            );
        }

        // Check for excessive precision (storage waste)
        if ($precision > 30) {
            return $this->createExcessivePrecisionIssue(
                $classMetadata,
                $fieldName,
                $precision,
            );
        }

        return null;
    }

    private function isMoneyField(string $fieldName): bool
    {
        $moneyPatterns = ['price', 'amount', 'cost', 'total', 'balance', 'fee', 'charge', 'payment'];
        $fieldLower    = strtolower($fieldName);

        Assert::isIterable($moneyPatterns, '$moneyPatterns must be iterable');
        return array_any($moneyPatterns, fn ($moneyPattern) => str_contains($fieldLower, (string) $moneyPattern));
    }

    private function isPercentageField(string $fieldName): bool
    {
        $percentagePatterns = ['percent', 'percentage', 'rate', 'ratio'];
        $fieldLower         = strtolower($fieldName);

        Assert::isIterable($percentagePatterns, '$percentagePatterns must be iterable');
        return array_any($percentagePatterns, fn ($percentagePattern) => str_contains($fieldLower, (string) $percentagePattern));
    }

    private function createInsufficientPrecisionIssue(
        ClassMetadata $classMetadata,
        string $fieldName,
        int $currentPrecision,
        int $currentScale,
        string $useCase,
    ): IssueInterface {
        $className      = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);
        $recommended    = self::RECOMMENDED_CONFIGS[$useCase];

        $description = sprintf(
            'Entity %s::$%s has precision=%d, scale=%d which may be insufficient for %s values. ' .
            'Recommended: precision=%d, scale=%d.',
            $shortClassName,
            $fieldName,
            $currentPrecision,
            $currentScale,
            $useCase,
            $recommended['precision'],
            $recommended['scale'],
        );

        $badCode = <<<PHP
            #[ORM\Column(type: 'decimal', precision: {$currentPrecision}, scale: {$currentScale})]
            public string \${$fieldName};
            // Can cause truncations or overflows
            PHP;

        $goodCode = <<<PHP
            #[ORM\Column(type: 'decimal', precision: {$recommended['precision']}, scale: {$recommended['scale']})]
            public string \${$fieldName};
            // Sufficient for most {$useCase} cases
            PHP;

        $infoMessage = sprintf(
            'Precision %d allows numbers up to %s',
            $recommended['precision'],
            str_repeat('9', $recommended['precision'] - $recommended['scale']) . '.' . str_repeat('9', $recommended['scale']),
        );

        $suggestionMetadata = new SuggestionMetadata(
            type: SuggestionType::integrity(),
            severity: Severity::warning(),
            title: 'Increase Decimal Precision',
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'decimal_insufficient_precision',
            'title'       => sprintf('Insufficient Decimal Precision: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'warning',
            'category'    => 'configuration',
            'suggestion'  => $this->suggestionFactory->createFromTemplate(
                'decimal_insufficient_precision',
                [
                    'bad_code'     => $badCode,
                    'good_code'    => $goodCode,
                    'description'  => $description,
                    'info_message' => $infoMessage,
                ],
                $suggestionMetadata,
            ),
        ]);
    }

    private function createUnusualScaleIssue(
        ClassMetadata $classMetadata,
        string $fieldName,
        int $scale,
        string $useCase,
    ): IssueInterface {
        $className      = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $description = sprintf(
            'Entity %s::$%s has scale=%d which is unusual for %s. ' .
            'Most currencies use 2 decimal places, some use 4 for precision.',
            $shortClassName,
            $fieldName,
            $scale,
            $useCase,
        );

        $currencyScales = [
            'scale=2: Most currencies (USD, EUR, GBP, etc.)',
            'scale=3: Some Middle Eastern currencies (KWD, BHD, OMR)',
            'scale=4: High-precision financial calculations',
            'scale=0: Japanese Yen (JPY), Korean Won (KRW)',
        ];

        $infoMessage = 'If this is intentional, you can ignore this warning. Otherwise, consider using scale=2 or scale=4.';

        $suggestionMetadata = new SuggestionMetadata(
            type: SuggestionType::integrity(),
            severity: Severity::info(),
            title: 'Review Decimal Scale',
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'decimal_unusual_scale',
            'title'       => sprintf('Unusual Decimal Scale: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'info',
            'category'    => 'configuration',
            'suggestion'  => $this->suggestionFactory->createFromTemplate(
                'decimal_unusual_scale',
                [
                    'description'     => $description,
                    'currency_scales' => $currencyScales,
                    'info_message'    => $infoMessage,
                ],
                $suggestionMetadata,
            ),
        ]);
    }

    private function createExcessivePrecisionIssue(
        ClassMetadata $classMetadata,
        string $fieldName,
        int $precision,
    ): IssueInterface {
        $className      = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $description = sprintf(
            'Entity %s::$%s has precision=%d which is very high and may waste storage. ' .
            'Consider if you really need this level of precision.',
            $shortClassName,
            $fieldName,
            $precision,
        );

        $precisionNeeds = [
            'Money: precision=10-19',
            'Percentages: precision=5',
            'Scientific: precision varies, but rarely > 30',
            'Coordinates: precision=10-15',
        ];

        $suggestionMetadata = new SuggestionMetadata(
            type: SuggestionType::integrity(),
            severity: Severity::info(),
            title: 'Consider Reducing Precision',
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'decimal_excessive_precision',
            'title'       => sprintf('Excessive Decimal Precision: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'info',
            'category'    => 'configuration',
            'suggestion'  => $this->suggestionFactory->createFromTemplate(
                'decimal_excessive_precision',
                [
                    'description'     => $description,
                    'precision_needs' => $precisionNeeds,
                ],
                $suggestionMetadata,
            ),
        ]);
    }

    private function createPrecisionSuggestion(string $fieldName): mixed
    {
        $options = [];

        foreach (self::RECOMMENDED_CONFIGS as $useCase => $config) {
            $options[] = [
                'title'       => ucfirst($useCase) . ' Values',
                'description' => sprintf(
                    'For %s values: precision=%d, scale=%d',
                    $useCase,
                    $config['precision'],
                    $config['scale'],
                ),
                'code' => sprintf(
                    "#[ORM\Column(type: 'decimal', precision: %d, scale: %d)]
public string \$%s;",
                    $config['precision'],
                    $config['scale'],
                    $fieldName,
                ),
            ];
        }

        $understandingPoints = [
            'Precision: Total number of digits (before + after decimal point)',
            'Scale: Number of digits after decimal point',
            'Example: DECIMAL(10,2) = 12345678.90 (10 total digits, 2 after decimal)',
        ];

        $infoMessage = 'Database defaults vary: MySQL defaults to (10,0), PostgreSQL to implementation-defined.';

        $suggestionMetadata = new SuggestionMetadata(
            type: SuggestionType::integrity(),
            severity: Severity::warning(),
            title: 'Add Explicit Precision/Scale',
        );

        return $this->suggestionFactory->createFromTemplate(
            'decimal_missing_precision',
            [
                'options'              => $options,
                'understanding_points' => $understandingPoints,
                'info_message'         => $infoMessage,
            ],
            $suggestionMetadata,
        );
    }
}
