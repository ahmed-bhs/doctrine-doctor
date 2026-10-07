<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Collector;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;

/**
 * Records the queries it is given, optionally reporting one issue.
 */
final class RecordingAnalyzer implements AnalyzerInterface
{
    public int $calls = 0;

    /** @var list<string> */
    public array $sqls = [];

    public function __construct(
        private readonly bool $reportIssue = false,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        ++$this->calls;

        foreach ($queryDataCollection as $query) {
            $this->sqls[] = $query->sql;
        }

        return IssueCollection::fromArray($this->reportIssue ? [new PerformanceIssue([
            'type' => 'slow_query',
            'title' => 'Slow query',
            'description' => 'A slow query was detected.',
            'severity' => 'warning',
            'queries' => [],
        ])] : []);
    }
}
