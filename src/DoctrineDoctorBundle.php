<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor;

use AhmedBhs\DoctrineDoctor\DependencyInjection\Compiler\ConditionalLoggerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use function dirname;

/**
 * Doctrine Doctor Bundle - Runtime Analysis Tool for Doctrine ORM.
 * This development bundle integrates into the Symfony Web Profiler to provide
 * real-time analysis of Doctrine ORM usage during development. Unlike static
 * analysis tools (PHPStan, Psalm), it analyzes actual query execution at runtime
 * to detect:
 * - Performance bottlenecks (N+1 queries, missing indexes, slow queries)
 * - Security vulnerabilities (DQL/SQL injection, sensitive data exposure)
 * - Best practice violations (cascade configs, type mismatches, naming conventions)
 * The bundle operates in the Web Profiler's late data collection phase, running
 * analysis after the HTTP response has been sent to avoid impacting request time.
 * Key Features:
 * - 68 specialized analyzers across 5 categories
 * - Zero runtime overhead (analysis runs post-response)
 * - Actionable suggestions with code examples
 * - Real execution context (actual data, parameters, EXPLAIN plans)
 * @see https://github.com/ahmed-bhs/doctrine-doctor
 */
class DoctrineDoctorBundle extends Bundle
{
    public function getPath(): string
    {
        return dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Register compiler pass to conditionally disable logging for performance
        // When debug.internal_logging is false (default), all loggers become NullLogger
        // This saves ~133ms overhead from Monolog calls
        $container->addCompilerPass(new ConditionalLoggerPass());
    }
}
