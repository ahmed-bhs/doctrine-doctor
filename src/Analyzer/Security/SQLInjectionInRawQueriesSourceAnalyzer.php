<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Security;

use AhmedBhs\DoctrineDoctor\Analyzer\StaticAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;

/**
 * Static source-code half of SQLInjectionInRawQueriesAnalyzer.
 */
class SQLInjectionInRawQueriesSourceAnalyzer implements StaticAnalyzerInterface
{
    public function __construct(
        private readonly SQLInjectionInRawQueriesAnalyzer $runtimeAnalyzer,
    ) {
    }

    /**
     * @SuppressWarnings(UnusedFormalParameter)
     */
    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return $this->runtimeAnalyzer->analyzeSourceCode();
    }
}
