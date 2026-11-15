<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Security;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\DQLPatternMatcher;
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\InjectionPatternDetector;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Webmozart\Assert\Assert;

class DQLInjectionAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly InjectionPatternDetector $injectionDetector = new InjectionPatternDetector(),
        private readonly DQLPatternMatcher $dqlPatternMatcher = new DQLPatternMatcher(),
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        $suspiciousQueries = [];
        $unparameterizedDqlQueries = [];

        foreach ($queryDataCollection as $queryData) {
            $injectionRisk = $this->detectInjectionRisk($queryData->sql);
            Assert::isArray($injectionRisk);
            $riskLevel = $injectionRisk['risk_level'] ?? 0;
            Assert::integer($riskLevel);

            // One issue per query: a critical pattern (tautology, UNION...) says the most;
            // otherwise the unparameterized-literal diagnosis is the precise one.
            if ($riskLevel < 3 && $this->isDqlWithUnparameterizedLiteral($queryData)) {
                $unparameterizedDqlQueries[] = $queryData;

                continue;
            }

            if ($riskLevel > 0) {
                $suspiciousQueries[] = [
                    'query'      => $queryData,
                    'risk_level' => $riskLevel,
                    'indicators' => $injectionRisk['indicators'] ?? [],
                ];
            }
        }

        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($suspiciousQueries, $unparameterizedDqlQueries) {
                if ([] !== $suspiciousQueries) {
                    yield from $this->yieldPatternBasedIssues($suspiciousQueries);
                }

                foreach ($unparameterizedDqlQueries as $queryData) {
                    yield $this->createUnparameterizedDqlIssue($queryData);
                }
            },
        );
    }

    /**
     * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
     */
    private function yieldPatternBasedIssues(array $suspiciousQueries): \Generator
    {
        $criticalQueries = array_values(array_filter($suspiciousQueries, function (array $query): bool {
            $riskLevel = $query['risk_level'];
            Assert::integer($riskLevel);
            return $riskLevel >= 3;
        }));

        if ([] !== $criticalQueries) {
            $indicators   = $this->aggregateIndicators($criticalQueries);
            Assert::isArray($indicators);
            $queryObjects = array_column(array_slice($criticalQueries, 0, 10), 'query');
            $firstQuery   = $criticalQueries[0]['query']->sql ?? '';

            $suggestion = $this->suggestionFactory->createFromTemplate(
                templateName: 'Security/dql_injection',
                context: [
                    'query' => $firstQuery,
                    'vulnerable_parameters' => $indicators,
                    'risk_level' => 'critical',
                ],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::security(),
                    severity: Severity::critical(),
                    title: 'DQL Injection Vulnerability Detected',
                    tags: ['security', 'injection', 'dql'],
                ),
            );

            yield $this->issueFactory->create(new IssueData(
                type: IssueType::DQL_INJECTION->value,
                title: sprintf('Security Vulnerability: %d queries with SQL injection risks', count($criticalQueries)),
                description: DescriptionHighlighter::highlight(
                    '{count} queries carry a critical injection risk ({indicators}). '
                    . 'Input concatenated into the query can change its meaning and read or modify any data.',
                    [
                        'count' => (string) count($criticalQueries),
                        'indicators' => implode(', ', $indicators),
                    ],
                ),
                severity: $suggestion->getMetadata()->severity,
                suggestion: $suggestion,
                queries: $queryObjects,
                backtrace: $criticalQueries[0]['query']->backtrace,
            ));
        }

        $highRiskQueries = array_values(array_filter($suspiciousQueries, function (array $query): bool {
            $riskLevel = $query['risk_level'];
            Assert::integer($riskLevel);
            return 2 === $riskLevel;
        }));

        if ([] !== $highRiskQueries) {
            $indicators   = $this->aggregateIndicators($highRiskQueries);
            Assert::isArray($indicators);
            $queryObjects = array_column(array_slice($highRiskQueries, 0, 10), 'query');
            $firstQuery   = $highRiskQueries[0]['query']->sql ?? '';

            $suggestion = $this->suggestionFactory->createFromTemplate(
                templateName: 'Security/dql_injection',
                context: [
                    'query' => $firstQuery,
                    'vulnerable_parameters' => $indicators,
                    'risk_level' => 'warning',
                ],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::security(),
                    severity: Severity::critical(),
                    title: 'DQL Injection Vulnerability Detected',
                    tags: ['security', 'injection', 'dql'],
                ),
            );

            yield $this->issueFactory->create(new IssueData(
                type: IssueType::DQL_INJECTION->value,
                title: sprintf('Security Warning: %d queries with potential injection risks', count($highRiskQueries)),
                description: sprintf(
                    '%d queries carry a high injection risk (%s). '
                    . 'If any of these values comes from user input, it can change the meaning of the query.',
                    count($highRiskQueries),
                    implode(', ', $indicators),
                ),
                severity: $suggestion->getMetadata()->severity,
                suggestion: $suggestion,
                queries: $queryObjects,
                backtrace: $highRiskQueries[0]['query']->backtrace,
            ));
        }
    }

    private function isDqlWithUnparameterizedLiteral(QueryData $queryData): bool
    {
        // Bound parameters elsewhere in the query do not make a concatenated value safe: "locale = ? AND name LIKE
        // '%lin%'" still carries the search terms in the SQL text.
        if (!$this->dqlPatternMatcher->hasDoctrineSQLPattern($queryData->sql)) {
            return false;
        }

        $literals = $this->whereLiterals($queryData->sql);

        return [] !== $literals && !$this->literalsAreWrittenInSource($queryData, $literals);
    }

    /**
     * Quoted values compared in the WHERE clause (=, <>, !=, LIKE).
     *
     * @return list<string>
     */
    private function whereLiterals(string $sql): array
    {
        $where = stristr($sql, ' WHERE ');
        if (false === $where) {
            return [];
        }

        preg_match_all("/(?:=|<>|!=|\\bLIKE)\\s*'([^']*)'/i", $where, $matches);

        return $matches[1];
    }

    /**
     * Every literal of the SQL is written as is in a file that built the query, the application or a library such
     * as a shop framework: the value is a constant of the code, such as o.state = 'completed', not user input.
     *
     * @param list<string> $literals
     */
    private function literalsAreWrittenInSource(QueryData $queryData, array $literals): bool
    {
        foreach ($queryData->backtrace ?? [] as $frame) {
            $file = \is_array($frame) && isset($frame['file']) && \is_string($frame['file']) ? $frame['file'] : '';
            if ('' === $file || 1 === preg_match('#/vendor/(?:doctrine|symfony)/#', $file) || !is_file($file)) {
                continue;
            }

            $source = (string) file_get_contents($file);
            if (array_all($literals, fn ($value) => 1 === preg_match("/(?:=|<>|!=|LIKE)\\s*\\\\?'" . preg_quote($value, '/') . "\\\\?'/i", $source))) {
                return true;
            }
        }

        return false;
    }

    private function createUnparameterizedDqlIssue(QueryData $queryData): \AhmedBhs\DoctrineDoctor\Issue\IssueInterface
    {
        $suggestion = $this->suggestionFactory->createFromTemplate(
            templateName: 'Security/dql_injection',
            context: [
                'query' => $queryData->sql,
                'vulnerable_parameters' => ['Literal value in WHERE clause without parameter binding'],
                'risk_level' => 'critical',
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::security(),
                severity: Severity::critical(),
                title: 'DQL Injection Risk: Unparameterized Literal',
                tags: ['security', 'injection', 'dql'],
            ),
        );

        return $this->issueFactory->create(new IssueData(
            type: IssueType::DQL_INJECTION->value,
            title: 'DQL Injection Risk: Doctrine query with concatenated literal value',
            description: DescriptionHighlighter::highlight(
                'Doctrine-generated SQL has a literal value in its WHERE clause and no bound parameter: {query}. '
                . 'The DQL was built by string concatenation, so any user input in it can change the query.',
                ['query' => substr($queryData->sql, 0, 120)],
            ),
            severity: $suggestion->getMetadata()->severity,
            suggestion: $suggestion,
            queries: [$queryData],
            backtrace: $queryData->backtrace,
        ));
    }

    /**
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

        foreach ($queries as $query) {
            $indicators = $query['indicators'] ?? [];
            Assert::isArray($indicators);
            $allIndicators = array_merge($allIndicators, $indicators);
        }

        return array_values(array_unique($allIndicators));
    }
}
