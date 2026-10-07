<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\AiMate;

use Symfony\Component\Process\Process;

/**
 * Runs doctrine:doctor:analyze-profile in the application.
 *
 * AI Mate reads profiles from disk without booting the application, so it has
 * neither the analyzers nor the EntityManager: the analysis has to run in the
 * application's own console.
 */
final readonly class ConsolePendingAnalysisRunner implements PendingAnalysisRunnerInterface
{
    public function __construct(
        private string $projectDir,
        private string $environment = 'dev',
        private float $timeout = 120.0,
    ) {
    }

    public function run(string $token): bool
    {
        $console = $this->projectDir . '/bin/console';

        if (!is_file($console)) {
            return false;
        }

        $process = new Process(
            [\PHP_BINARY, $console, 'doctrine:doctor:analyze-profile', $token, '--env=' . $this->environment, '--no-interaction'],
            $this->projectDir,
            timeout: $this->timeout,
        );

        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful();
    }
}
