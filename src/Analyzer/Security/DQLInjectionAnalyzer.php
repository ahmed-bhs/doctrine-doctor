<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Security;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\InjectionPatternDetector;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use Webmozart\Assert\Assert;

class DQLInjectionAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly InjectionPatternDetector $injectionDetector = new InjectionPatternDetector(),
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        $suspiciousQueries = [];

        // Detect potential SQL injection patterns
        Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

        foreach ($queryDataCollection as $queryData) {
            $injectionRisk = $this->detectInjectionRisk($queryData->sql);
            Assert::isArray($injectionRisk);
            $riskLevel = $injectionRisk['risk_level'] ?? 0;
            Assert::integer($riskLevel);

            if ($riskLevel > 0) {
                $suspiciousQueries[] = [
                    'query'      => $queryData,
                    'risk_level' => $riskLevel,
                    'indicators' => $injectionRisk['indicators'] ?? [],
                ];
            }
        }

        //  Use generator for memory efficiency
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($suspiciousQueries) {
                if ([] === $suspiciousQueries) {
                    return;
                }

                // Critical risk queries
                $criticalQueries = array_values(array_filter($suspiciousQueries, function (array $q): bool {
                    $riskLevel = $q['risk_level'];
                    Assert::integer($riskLevel);
                    return $riskLevel >= 3;
                }));

                if ([] !== $criticalQueries) {
                    $indicators   = $this->aggregateIndicators($criticalQueries);
                    Assert::isArray($indicators);
                    $queryObjects = array_column(array_slice($criticalQueries, 0, 10), 'query');
                    $firstQuery   = $criticalQueries[0]['query']->sql ?? '';

                    $suggestion = $this->suggestionFactory->createDQLInjection(
                        query: $firstQuery,
                        vulnerableParameters: $indicators,
                        riskLevel: 'critical',
                    );

                    $issueData = new IssueData(
                        type: 'dql_injection',
                        title: sprintf('Security Vulnerability: %d queries with SQL injection risks', count($criticalQueries)),
                        description: DescriptionHighlighter::highlight(
                            'Detected {count} queries with CRITICAL injection risk. Indicators: {indicators}. ' .
                            'Always use parameterized queries and never concatenate user input',
                            [
                                'count' => (string) count($criticalQueries),
                                'indicators' => implode(', ', $indicators),
                            ],
                        ),
                        severity: $suggestion->getMetadata()->severity,
                        suggestion: $suggestion,
                        queries: $queryObjects,
                        backtrace: $criticalQueries[0]['query']->backtrace,
                    );

                    yield $this->issueFactory->create($issueData);
                }

                // High risk queries
                $highRiskQueries = array_values(array_filter($suspiciousQueries, function (array $q): bool {
                    $riskLevel = $q['risk_level'];
                    Assert::integer($riskLevel);
                    return 2 === $riskLevel;
                }));

                if ([] !== $highRiskQueries) {
                    $indicators   = $this->aggregateIndicators($highRiskQueries);
                    Assert::isArray($indicators);
                    $queryObjects = array_column(array_slice($highRiskQueries, 0, 10), 'query');
                    $firstQuery   = $highRiskQueries[0]['query']->sql ?? '';

                    $suggestion = $this->suggestionFactory->createDQLInjection(
                        query: $firstQuery,
                        vulnerableParameters: $indicators,
                        riskLevel: 'warning',
                    );

                    $issueData = new IssueData(
                        type: 'dql_injection',
                        title: sprintf('Security Warning: %d queries with potential injection risks', count($highRiskQueries)),
                        description: sprintf(
                            'Detected %d queries with HIGH injection risk. Indicators: %s. ' .
                            'Review these queries and ensure proper parameter binding',
                            count($highRiskQueries),
                            implode(', ', $indicators),
                        ),
                        severity: $suggestion->getMetadata()->severity,
                        suggestion: $suggestion,
                        queries: $queryObjects,
                        backtrace: $highRiskQueries[0]['query']->backtrace,
                    );

                    yield $this->issueFactory->create($issueData);
                }
            },
        );
    }

    /**
     * Delegate injection detection to InjectionPatternDetector.
     * @return array{risk_level: int, indicators: list<string>}
     */
    private function detectInjectionRisk(string $sql): array
    {
        return $this->injectionDetector->detectInjectionRisk($sql);
    }

    /**
     * @param list<array{query: mixed, risk_level: int, indicators: list<string>}> $queries
     * @return list<string>
     */
    private function aggregateIndicators(array $queries): array
    {
        $allIndicators = [];

        Assert::isIterable($queries, '$queries must be iterable');

        foreach ($queries as $query) {
            $indicators = $query['indicators'] ?? [];
            Assert::isArray($indicators);
            $allIndicators = array_merge($allIndicators, $indicators);
        }

        return array_values(array_unique($allIndicators));
    }
}
