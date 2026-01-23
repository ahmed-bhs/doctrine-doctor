<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector;

use AhmedBhs\DoctrineDoctor\Collector\Helper\DataCollectorLogger;
use AhmedBhs\DoctrineDoctor\Collector\Helper\IssueReconstructor;
use AhmedBhs\DoctrineDoctor\Collector\Helper\QueryStatsCalculator;
use AhmedBhs\DoctrineDoctor\Service\IssueDeduplicator;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Data Transfer Object for services stored in ServiceHolder.
 *
 * Important: This class intentionally does NOT store EntityManager or other
 * Doctrine objects. In worker mode (FrankenPHP, RoadRunner), these objects
 * become invalid between requests and cause segfaults. Only stateless services
 * that don't hold database connections should be stored here.
 */
final class ServiceHolderData
{
    public function __construct(
        /**
         * @readonly
         */
        public iterable $analyzers,
        /**
         * @readonly
         */
        public ?Stopwatch $stopwatch,
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
