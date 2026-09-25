<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Command;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\DatabaseAuditAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\StaticAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Service\IssueDeduplicator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'doctrine:doctor:analyze',
    description: 'Run Doctrine Doctor source and mapping analyzers for CI.',
)]
class AnalyzeCommand extends Command
{
    /**
     * @param iterable<AnalyzerInterface> $analyzers
     */
    public function __construct(
        private readonly iterable $analyzers,
        private readonly IssueDeduplicator $issueDeduplicator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('with-database', null, InputOption::VALUE_NONE, 'Include audits that query the configured database.')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Minimum issue severity that fails the command: critical, warning, info, or never.', 'warning');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $failOn = $input->getOption('fail-on');

        if (!\in_array($failOn, ['critical', 'warning', 'info', 'never'], true)) {
            $io->error('The --fail-on value must be critical, warning, info, or never.');

            return self::INVALID;
        }

        $includeDatabaseAudits = (bool) $input->getOption('with-database');
        $issues = [];
        $analyzerErrors = [];
        $analyzerTimings = [];
        $analyzerCount = 0;
        $startedAt = hrtime(true);

        foreach ($this->analyzers as $analyzer) {
            if (!$analyzer instanceof StaticAnalyzerInterface) {
                continue;
            }

            if ($analyzer instanceof DatabaseAuditAnalyzerInterface && !$includeDatabaseAudits) {
                continue;
            }

            ++$analyzerCount;
            $analyzerStartedAt = hrtime(true);
            $analyzerIssueCount = 0;

            try {
                foreach ($analyzer->analyze(QueryDataCollection::empty()) as $issue) {
                    $issues[] = $issue;
                    ++$analyzerIssueCount;
                }
            } catch (\Throwable $throwable) {
                $analyzerErrors[] = sprintf('%s: %s', $analyzer::class, $throwable->getMessage());
            } finally {
                $analyzerTimings[$analyzer::class] = [
                    'issues_found' => $analyzerIssueCount,
                    'execution_time_ms' => round((hrtime(true) - $analyzerStartedAt) / 1_000_000, 2),
                ];
            }
        }

        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
        $deduplicatedIssues = $this->issueDeduplicator
            ->deduplicate(IssueCollection::fromArray($issues))
            ->sorting()
            ->bySeverityDescending()
            ->toArray();

        $rows = array_map(
            static fn (IssueInterface $issue): array => [
                strtoupper($issue->getSeverity()->getValue()),
                $issue->getCategory()->value,
                $issue->getTitle(),
            ],
            $deduplicatedIssues,
        );

        $io->title('Doctrine Doctor static analysis');
        $io->text(sprintf('Analyzers: %d | Findings: %d | Time: %.2f ms', $analyzerCount, count($deduplicatedIssues), $durationMs));

        if ([] !== $analyzerTimings && $output->isVerbose()) {
            uasort($analyzerTimings, static fn (array $left, array $right): int => $right['execution_time_ms'] <=> $left['execution_time_ms']);
            $io->section('Analyzer timings');
            $io->table(
                ['Analyzer', 'Findings', 'Time'],
                array_map(
                    static fn (string $class, array $stats): array => [
                        (new \ReflectionClass($class))->getShortName(),
                        $stats['issues_found'],
                        sprintf('%.2f ms', $stats['execution_time_ms']),
                    ],
                    array_keys($analyzerTimings),
                    array_values($analyzerTimings),
                ),
            );
        }

        if ([] !== $rows) {
            $io->table(['Severity', 'Category', 'Finding'], $rows);
        } else {
            $io->success('No findings detected.');
        }

        if ([] !== $analyzerErrors) {
            $io->error(array_merge(['One or more analyzers failed:'], $analyzerErrors));

            return self::FAILURE;
        }

        $failPriority = match ($failOn) {
            'critical' => 3,
            'warning' => 2,
            'info' => 1,
            default => PHP_INT_MAX,
        };

        foreach ($deduplicatedIssues as $issue) {
            if ($issue->getSeverity()->getPriority() >= $failPriority) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
