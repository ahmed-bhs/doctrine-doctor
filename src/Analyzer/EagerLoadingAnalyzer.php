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
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;

class EagerLoadingAnalyzer implements AnalyzerInterface
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
        /**
         * @readonly
         */
        private int $joinThreshold = 4,
        /**
         * @readonly
         */
        private int $criticalJoinThreshold = 7,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        //  Article pattern: Use generator instead of array
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $queryData) {
                    $joinCount = $this->countJoins($queryData->sql);

                    if ($joinCount >= $this->joinThreshold) {
                        $issueData = new IssueData(
                            type: 'eager_loading',
                            title: sprintf('Excessive Eager Loading: %d JOINs', $joinCount),
                            description: DescriptionHighlighter::highlight(
                                'Query contains {count} JOINs which may cause cartesian product issues (threshold: {threshold})',
                                [
                                    'count' => $joinCount,
                                    'threshold' => $this->joinThreshold,
                                ],
                            ),
                            severity: $joinCount > $this->criticalJoinThreshold ? Severity::critical() : Severity::warning(),
                            suggestion: $this->generateSuggestion($joinCount),
                            queries: [$queryData],
                            backtrace: $queryData->backtrace,
                        );

                        yield $this->issueFactory->create($issueData);
                    }
                }
            },
        );
    }

    private function countJoins(string $sql): int
    {
        // Count all JOINs by simply counting the keyword "JOIN"
        // This catches: INNER JOIN, LEFT JOIN, LEFT OUTER JOIN, RIGHT JOIN, FULL JOIN, CROSS JOIN, JOIN
        $count = preg_match_all('/\bJOIN\b/i', $sql);
        return false === $count ? 0 : $count;
    }

    private function generateSuggestion(int $joinCount): SuggestionInterface
    {
        $suggestions = [];

        if ($joinCount > $this->criticalJoinThreshold) {
            $suggestions[] = sprintf('// CRITICAL: %d JOINs is excessive!', $joinCount);
        }

        $suggestions[] = sprintf('// Review if all %d JOINs are necessary', $joinCount);
        $suggestions[] = '';
        $suggestions[] = '// Option 1: Use fetch="EXTRA_LAZY" for collections';
        $suggestions[] = '/**';
        $suggestions[] = " * @ORM\OneToMany(targetEntity=\"Item\", mappedBy=\"parent\", fetch=\"EXTRA_LAZY\")";
        $suggestions[] = ' *';
        $suggestions[] = ' */';
        $suggestions[] = 'private Collection $items;';
        $suggestions[] = '';
        $suggestions[] = '// Option 2: Split into multiple queries if not all data needed immediately';
        $suggestions[] = '// First query: Get main entities';
        $suggestions[] = '$entities = $repository->findAll();';
        $suggestions[] = '// Second query: Load specific relation only when needed';
        $suggestions[] = '$repository->loadRelation($entities, \'specificRelation\');';
        $suggestions[] = '';
        $suggestions[] = '// Option 3: Use partial objects to load only required fields';
        $suggestions[] = 'SELECT PARTIAL e.{id, name}, PARTIAL r.{id, title}';
        $suggestions[] = 'FROM Entity e JOIN e.relation r';
        $suggestions[] = '';
        $suggestions[] = '// Option 4: Consider if you really need eager loading';
        $suggestions[] = '// Maybe lazy loading with careful N+1 prevention is better';
        $suggestions[] = '';
        $suggestions[] = '// Option 5: Use DTOs for read-only views';
        $suggestions[] = "SELECT NEW App\DTO\EntityDTO(e.id, e.name, r.title)";
        $suggestions[] = 'FROM Entity e JOIN e.relation r';

        return $this->suggestionFactory->createQueryOptimization(
            code: implode("
", $suggestions),
            optimization: sprintf(
                'Excessive JOINs (%d) might indicate over-fetching data through eager loading. ' .
                'This can significantly impact query performance and memory usage.',
                $joinCount,
            ),
            executionTime: 0.0,
            threshold: $this->joinThreshold,
        );
    }
}
