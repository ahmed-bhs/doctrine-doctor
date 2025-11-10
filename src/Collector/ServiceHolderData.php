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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Data Transfer Object for services stored in ServiceHolder.
 * Reduces parameter count by encapsulating related services.
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
        public ?EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        public ?Stopwatch $stopwatch,
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
