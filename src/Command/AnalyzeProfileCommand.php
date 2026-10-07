<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Command;

use AhmedBhs\DoctrineDoctor\Collector\PendingProfileAnalyzer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Completes the deferred analysis of stored profiler profiles.
 *
 * Used by tools that read profiles outside the application (AI Mate) when a
 * profile was collected with AnalysisTiming::OnView and never opened in the
 * web debug toolbar or the profiler.
 */
#[AsCommand(
    name: 'doctrine:doctor:analyze-profile',
    description: 'Run the pending Doctrine Doctor analysis of stored profiler profiles',
)]
class AnalyzeProfileCommand extends Command
{
    public function __construct(
        private readonly PendingProfileAnalyzer $pendingProfileAnalyzer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('tokens', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Profiler tokens to analyze');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $tokens */
        $tokens = $input->getArgument('tokens');

        foreach ($tokens as $token) {
            $analyzed = $this->pendingProfileAnalyzer->analyze($token);

            $output->writeln(sprintf('%s: %s', $token, $analyzed ? 'analyzed' : 'nothing pending'));
        }

        return Command::SUCCESS;
    }
}
