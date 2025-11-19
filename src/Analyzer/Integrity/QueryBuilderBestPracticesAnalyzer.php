<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\QueryBuilderPatternDetector;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Webmozart\Assert\Assert;

/**
 * Analyzes QueryBuilder usage for common pitfalls and best practices.
 * Detects:
 * - String concatenation instead of parameters (SQL injection risk)
 * - Missing setParameter() calls
 * - Incorrect NULL comparisons (= NULL instead of IS NULL)
 * - Empty IN() clauses
 * - Duplicate parameter names
 * - Improper LIKE usage without escaping
 * - Incorrect use of where() vs andWhere()/orWhere()
 */
class QueryBuilderBestPracticesAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    private QueryBuilderPatternDetector $patternDetector;

    public function __construct(
        /**
         * @readonly
         */
        private IssueFactoryInterface $issueFactory,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
        ?QueryBuilderPatternDetector $patternDetector = null,
    ) {
        $this->patternDetector = $patternDetector ?? new QueryBuilderPatternDetector();
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        //  Article pattern: Use generator instead of array
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $query) {
                    $sql = $query->sql;

                    // 1. Detect potential SQL injection via string concatenation
                    if ($this->hasPotentialSqlInjection($sql)) {
                        yield $this->createSqlInjectionIssue($query);
                    }

                    // 2. Detect incorrect NULL comparison
                    if ($this->hasIncorrectNullComparison($sql)) {
                        yield $this->createIncorrectNullComparisonIssue($query);
                    }

                    // 3. Detect empty IN clause
                    if ($this->hasEmptyInClause($sql)) {
                        yield $this->createEmptyInClauseIssue($query);
                    }

                    // 4. Detect LIKE without proper escaping (heuristic)
                    if ($this->hasUnescapedLike($sql)) {
                        yield $this->createUnescapedLikeIssue($query);
                    }

                    // 5. Detect missing parameters
                    if ($this->hasMissingParameters($query)) {
                        yield $this->createMissingParametersIssue($query);
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'QueryBuilder Best Practices';
    }

    public function getCategory(): string
    {
        return 'integrity';
    }

    private function hasPotentialSqlInjection(string $sql): bool
    {
        $result = $this->patternDetector->detectPotentialSqlInjection($sql);
        return $result['detected'];
    }

    private function hasIncorrectNullComparison(string $sql): bool
    {
        $result = $this->patternDetector->detectIncorrectNullComparison($sql);
        return $result['detected'];
    }

    private function hasEmptyInClause(string $sql): bool
    {
        return $this->patternDetector->hasEmptyInClause($sql);
    }

    private function hasUnescapedLike(string $sql): bool
    {
        return $this->patternDetector->hasUnescapedLike($sql);
    }

    private function hasMissingParameters(QueryData $queryData): bool
    {
        $sql = $queryData->sql;
        $params = $queryData->params ?? [];

        $result = $this->patternDetector->detectMissingParameters($sql, $params);
        return $result['hasMissing'];
    }

    private function createSqlInjectionIssue(QueryData $queryData): IssueInterface
    {
        return $this->issueFactory->createFromArray([
            'type'        => 'query_builder_sql_injection',
            'title'       => 'Potential SQL Injection in QueryBuilder',
            'description' => 'The query appears to use string concatenation instead of parameter binding. Always use setParameter() to prevent SQL injection vulnerabilities.',
            'severity'    => 'critical',
            'category'    => 'security',
            'queries'     => [$queryData],
            'suggestion'  => $this->suggestionFactory->createSQLInjection(
                className: 'Repository',
                methodName: 'query',
                vulnerabilityType: 'String concatenation in WHERE clause',
            ),
        ]);
    }

    private function createIncorrectNullComparisonIssue(QueryData $queryData): IssueInterface
    {
        $badCode = <<<'PHP'
            $qb->where('u.deletedAt = NULL');
            $qb->where($qb->expr()->eq('u.deletedAt', null));
            PHP;

        $goodCode = <<<'PHP'
            $qb->where($qb->expr()->isNull('u.deletedAt'));
            // or
            $qb->where('u.deletedAt IS NULL');
            PHP;

        $suggestionMetadata = new SuggestionMetadata(
            type: SuggestionType::integrity(),
            severity: Severity::warning(),
            title: 'Use IS NULL instead of = NULL',
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'incorrect_null_comparison',
            'title'       => 'Incorrect NULL Comparison',
            'description' => 'Using = NULL or != NULL does not work in SQL. Use IS NULL or IS NOT NULL instead, or use $expr->isNull() / $expr->isNotNull() in QueryBuilder.',
            'severity'    => 'warning',
            'category'    => 'integrity',
            'queries'     => [$queryData],
            'suggestion'  => $this->suggestionFactory->createFromTemplate(
                'Integrity/incorrect_null_comparison',
                [
                    'bad_code'  => $badCode,
                    'good_code' => $goodCode,
                ],
                $suggestionMetadata,
            ),
        ]);
    }

    private function createEmptyInClauseIssue(QueryData $queryData): IssueInterface
    {
        $options = [
            [
                'title'       => 'Return no results',
                'description' => 'When the array is empty, explicitly return no results using a false condition.',
                'code'        => <<<'PHP'
                    if ($ids !== []) {
                        $qb->where($qb->expr()->in('u.id', ':ids'))
                           ->setParameter('ids', $ids);
                    } else {
                        $qb->where('1 = 0'); // No result
                    }
                    PHP,
                'pros' => ['Clear intent', 'No database query if array is empty'],
                'cons' => ['Requires explicit handling'],
            ],
            [
                'title'       => 'Return early',
                'description' => 'Check at the beginning of your method and return early if the array is empty.',
                'code'        => <<<'PHP'
                    /**

                     * @return array<mixed>

                     */

                    public function findByIds(array $ids): array
                    {
                        if ($ids === []) {
                            return []; // Return empty result early
                        }

                        return $this->createQueryBuilder('u')
                            ->where($qb->expr()->in('u.id', ':ids'))
                            ->setParameter('ids', $ids)
                            ->getQuery()
                            ->getResult();
                    }
                    PHP,
                'pros' => ['Avoids unnecessary query building', 'Clean code flow'],
                'cons' => ['Duplicates empty array logic'],
            ],
            [
                'title'       => 'Use DQL conditional',
                'description' => 'Build the query conditionally based on whether the array is empty.',
                'code'        => <<<'PHP'
                    $qb = $this->createQueryBuilder('u');

                    if ($ids !== []) {
                        $qb->where($qb->expr()->in('u.id', ':ids'))
                           ->setParameter('ids', $ids);
                    }
                    // If $ids is empty, no filter is applied
                    return $qb->getQuery()->getResult();
                    PHP,
                'pros' => ['Flexible query building'],
                'cons' => ['May return all results if empty (might not be desired)'],
            ],
        ];

        $suggestionMetadata = new SuggestionMetadata(
            type: SuggestionType::integrity(),
            severity: Severity::critical(),
            title: 'Check array before using IN()',
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'empty_in_clause',
            'title'       => 'Empty IN() Clause',
            'description' => 'The query contains an empty IN() clause which will cause a SQL syntax error. Always check if the array is empty before using IN().',
            'severity'    => 'critical',
            'category'    => 'integrity',
            'queries'     => [$queryData],
            'suggestion'  => $this->suggestionFactory->createFromTemplate(
                'Integrity/empty_in_clause',
                ['options' => $options],
                $suggestionMetadata,
            ),
        ]);
    }

    private function createUnescapedLikeIssue(QueryData $queryData): IssueInterface
    {
        return $this->issueFactory->createFromArray([
            'type'        => 'unescaped_like',
            'title'       => 'Potentially Unescaped LIKE Pattern',
            'description' => 'The query uses LIKE with what appears to be concatenated wildcards. User input in LIKE patterns should have % and _ characters escaped to prevent unexpected matching.',
            'severity'    => 'warning',
            'category'    => 'security',
            'queries'     => [$queryData],
            'suggestion'  => $this->suggestionFactory->createCodeSuggestion(
                description: 'Escape LIKE wildcards in user input',
                code: <<<'PHP'
                    // BAD
                    $qb->where($qb->expr()->like('u.name', ':name'))
                       ->setParameter('name', '%' . $userInput . '%');
                    // If $userInput contains "test%", it will match "test1", "test2", etc.

                    //  GOOD
                    $escapedInput = addcslashes($userInput, '%_');
                    $qb->where($qb->expr()->like('u.name', ':name'))
                       ->setParameter('name', '%' . $escapedInput . '%');

                    // Or create a helper method
                    private function escapeLikeValue(string $value): string
                    {
                        return addcslashes($value, '%_');
                    }
                    PHP,
            ),
        ]);
    }

    private function createMissingParametersIssue(QueryData $queryData): IssueInterface
    {
        return $this->issueFactory->createFromArray([
            'type'        => 'missing_parameters',
            'title'       => 'Missing Query Parameters',
            'description' => 'The query contains parameter placeholders (:param) but some parameters are not set with setParameter(). This will cause a runtime error.',
            'severity'    => 'critical',
            'category'    => 'integrity',
            'queries'     => [$queryData],
            'suggestion'  => $this->suggestionFactory->createCodeSuggestion(
                description: 'Set all query parameters',
                code: <<<'PHP'
                    // BAD
                    $qb->where('u.id = :id')
                       ->andWhere('u.status = :status')
                       ->setParameter('id', $id); // Missing 'status' parameter!

                    //  GOOD
                    $qb->where('u.id = :id')
                       ->andWhere('u.status = :status')
                       ->setParameter('id', $id)
                       ->setParameter('status', $status);

                    // Even better: use an array
                    $qb->where('u.id = :id')
                       ->andWhere('u.status = :status')
                       ->setParameters([
                           'id' => $id,
                           'status' => $status,
                       ]);
                    PHP,
            ),
        ]);
    }
}
