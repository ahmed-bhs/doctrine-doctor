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
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;

class DQLInjectionAnalyzer implements AnalyzerInterface
{
    public function __construct(
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
        $suspiciousQueries = [];

        // Detect potential SQL injection patterns
        assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

        foreach ($queryDataCollection as $queryData) {
            $injectionRisk = $this->detectInjectionRisk($queryData->sql);
            assert(is_array($injectionRisk));
            $riskLevel = $injectionRisk['risk_level'] ?? 0;
            assert(is_int($riskLevel));

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
                    assert(is_int($riskLevel));
                    return $riskLevel >= 3;
                }));

                if ([] !== $criticalQueries) {
                    $indicators   = $this->aggregateIndicators($criticalQueries);
                    assert(is_array($indicators));
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
                    assert(is_int($riskLevel));
                    return 2 === $riskLevel;
                }));

                if ([] !== $highRiskQueries) {
                    $indicators   = $this->aggregateIndicators($highRiskQueries);
                    assert(is_array($indicators));
                    $queryObjects = array_column(array_slice($highRiskQueries, 0, 10), 'query');
                    $firstQuery   = $highRiskQueries[0]['query']->sql ?? '';

                    $suggestion = $this->suggestionFactory->createDQLInjection(
                        query: $firstQuery,
                        vulnerableParameters: $indicators,
                        riskLevel: 'high',
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
     * @return array{risk_level: int, indicators: list<string>}
     */
    private function detectInjectionRisk(string $sql): array
    {
        $riskLevel  = 0;
        $indicators = [];

        // Pattern 1: String concatenation patterns (quotes with values)
        // Look for patterns like: 'value' or "value" that might indicate concatenation
        if (1 === preg_match("/['\"][^'\"]*\d+[^'\"]*['\"]/", $sql)) {
            ++$riskLevel;
            $indicators[] = 'Numeric value in quotes (possible concatenation)';
        }

        // Pattern 2: SQL keywords in quoted strings (UNION, OR, AND followed by 1=1, etc.)
        if (1 === preg_match("/'.*(?:UNION|OR\s+1\s*=\s*1|AND\s+1\s*=\s*1|--|\#|\/\*).*'/i", $sql)) {
            $riskLevel += 3;
            $indicators[] = 'SQL injection keywords detected in string';
        }

        // Pattern 3: Comments in strings
        if (1 === preg_match("/['\"].*(?:--|#|\/\*).*['\"]/", $sql)) {
            $riskLevel += 2;
            $indicators[] = 'SQL comment syntax in string value';
        }

        // Pattern 4: Multiple consecutive quotes (escape attempts)
        if (1 === preg_match("/'{2,}|(\"){2,}/", $sql)) {
            ++$riskLevel;
            $indicators[] = 'Consecutive quotes detected';
        }

        // Pattern 5: LIKE with unparameterized wildcards
        if (1 === preg_match("/LIKE\s+['\"][^?:]*%[^?:]*['\"]/i", $sql)) {
            ++$riskLevel;
            $indicators[] = 'LIKE clause without parameter';
        }

        // Pattern 6: WHERE clause without placeholders (improved)
        // Match WHERE clauses with literal string values instead of parameters
        if (1 === preg_match("/WHERE\s+[^=]+\s*=\s*'[^'?:]+'/i", $sql)) {
            $riskLevel += 2;
            $indicators[] = 'WHERE clause with literal string instead of parameter';
        }

        // Pattern 7: Multiple OR/AND conditions with literal strings (very suspicious)
        if (1 === preg_match("/(?:WHERE|AND|OR)\s+[^=]+\s*=\s*'[^']*'\s+(?:OR|AND)\s+/i", $sql)) {
            $riskLevel += 3;
            $indicators[] = 'Multiple conditions with literal strings (possible injection)';
        }

        return [
            'risk_level' => $riskLevel,
            'indicators' => $indicators,
        ];
    }

    /**
     * @param list<array{query: mixed, risk_level: int, indicators: list<string>}> $queries
     * @return list<string>
     */
    private function aggregateIndicators(array $queries): array
    {
        $allIndicators = [];

        assert(is_iterable($queries), '$queries must be iterable');

        foreach ($queries as $query) {
            $indicators = $query['indicators'] ?? [];
            assert(is_array($indicators));
            $allIndicators = array_merge($allIndicators, $indicators);
        }

        return array_values(array_unique($allIndicators));
    }
}
