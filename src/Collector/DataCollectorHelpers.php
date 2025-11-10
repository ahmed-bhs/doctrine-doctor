<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector;

use AhmedBhs\DoctrineDoctor\Collector\Helper\DatabaseInfoCollector;
use AhmedBhs\DoctrineDoctor\Collector\Helper\DataCollectorLogger;
use AhmedBhs\DoctrineDoctor\Collector\Helper\IssueReconstructor;
use AhmedBhs\DoctrineDoctor\Collector\Helper\QueryStatsCalculator;
use AhmedBhs\DoctrineDoctor\Service\IssueDeduplicator;

/**
 * Aggregates helper services for DoctrineDoctorDataCollector.
 * Reduces constructor parameter count by grouping related dependencies.
 */
final class DataCollectorHelpers
{
    public function __construct(
        /**
         * @readonly
         */
        public DatabaseInfoCollector $databaseInfoCollector,
        /**
         * @readonly
         */
        public IssueReconstructor $issueReconstructor,
        /**
         * @readonly
         */
        public QueryStatsCalculator $queryStatsCalculator,
        /**
         * @readonly
         */
        public DataCollectorLogger $dataCollectorLogger,
        /**
         * @readonly
         */
        public IssueDeduplicator $issueDeduplicator,
    ) {
    }
}
