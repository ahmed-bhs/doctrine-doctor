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
use AhmedBhs\DoctrineDoctor\Issue\FindingLocation;
use AhmedBhs\DoctrineDoctor\Issue\FindingLocator;
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
    private readonly FindingLocator $locator;

    public function __construct(
        private readonly iterable $analyzers,
        private readonly IssueDeduplicator $issueDeduplicator,
        ?string $projectDir = null,
    ) {
        $this->locator = new FindingLocator($projectDir);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('with-database', null, InputOption::VALUE_NONE, 'Include audits that query the configured database.')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Minimum issue severity that fails the command: critical, warning, info, or never.', 'warning')
            ->addOption('show-suggestion', null, InputOption::VALUE_REQUIRED, 'Show the full suggestion for a finding ID from the report.')
            ->addOption('error-format', null, InputOption::VALUE_REQUIRED, 'Output format: table, github or json. On GitHub Actions, table also prints GitHub annotations.', 'table')
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'Ignore the findings listed in this baseline file.')
            ->addOption('generate-baseline', null, InputOption::VALUE_OPTIONAL, 'Write every current finding to a baseline file and exit successfully.', false);
    }

    /**
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $failOn = $input->getOption('fail-on');
        $format = (string) $input->getOption('error-format');

        if (!\in_array($failOn, ['critical', 'warning', 'info', 'never'], true)) {
            $io->error('The --fail-on value must be critical, warning, info, or never.');

            return self::INVALID;
        }

        if (!\in_array($format, ['table', 'github', 'json'], true)) {
            $io->error('The --error-format value must be table, github or json.');

            return self::INVALID;
        }

        $analysis = $this->runStaticAnalyzers((bool) $input->getOption('with-database'));
        $analyzerErrors = $analysis['errors'];
        $issues = $this->issueDeduplicator
            ->deduplicate(IssueCollection::fromArray($analysis['issues']))
            ->sorting()
            ->bySeverityDescending()
            ->toArray();

        $findings = array_map($this->finding(...), array_values($issues));

        $generate = $input->getOption('generate-baseline');
        if (false !== $generate) {
            $file = \is_string($generate) && '' !== $generate ? $generate : 'doctrine-doctor-baseline.json';
            $this->writeBaseline($file, $findings);
            $io->success(sprintf('Baseline with %d findings written to %s.', count($findings), $file));

            return self::SUCCESS;
        }

        $ignored = 0;
        $unmatched = 0;
        $baselineFile = $input->getOption('baseline');
        if (\is_string($baselineFile) && '' !== $baselineFile) {
            $known = $this->readBaseline($baselineFile);
            if (null === $known) {
                $io->error(sprintf('Cannot read the baseline file %s.', $baselineFile));

                return self::INVALID;
            }
            $current = array_column($findings, 'id');
            $findings = array_values(array_filter($findings, static fn (array $finding): bool => !isset($known[$finding['id']])));
            $ignored = count($current) - count($findings);
            $unmatched = count(array_diff_key($known, array_flip($current)));
        }

        match ($format) {
            'json' => $output->writeln((string) json_encode(['totals' => $this->totals($findings), 'ignored_by_baseline' => $ignored, 'findings' => array_map(static fn (array $finding): array => array_diff_key($finding, ['issue' => true]), $findings)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'github' => $this->renderGithub($output, $findings),
            default => $this->renderTable($io, $output, $analysis, $findings, $ignored, $unmatched),
        };

        if (null !== $input->getOption('show-suggestion')) {
            $this->renderSuggestion($io, $findings, (string) $input->getOption('show-suggestion'));
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

        foreach ($findings as $finding) {
            if ($finding['issue']->getSeverity()->getPriority() >= $failPriority) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{id: string, severity: string, category: string, type: string, title: string, message: string, file: ?string, line: ?int, anchor: string, issue: IssueInterface}
     */
    private function finding(IssueInterface $issue): array
    {
        $location = $this->locator->locate($issue);

        return [
            'id' => $this->issueId($issue, $location),
            'severity' => $issue->getSeverity()->getValue(),
            'category' => $issue->getCategory()->value,
            'type' => $issue->getType(),
            'title' => $issue->getTitle(),
            'message' => $this->firstSentences($issue->getDescription()),
            'file' => $location->file,
            'line' => $location->line,
            'anchor' => $location->anchor,
            'issue' => $issue,
        ];
    }

    /**
     * @param array{issues: list<IssueInterface>, errors: list<string>, timings: array<string, array{issues_found: int, execution_time_ms: float}>, count: int, duration_ms: float} $analysis
     * @param list<array<string, mixed>> $findings
     */
    private function renderTable(SymfonyStyle $io, OutputInterface $output, array $analysis, array $findings, int $ignored, int $unmatched): void
    {
        $io->title('Doctrine Doctor static analysis');
        $this->renderSummary($io, $analysis['count'], $findings, $analysis['duration_ms'], $ignored);

        if ([] !== $analysis['timings'] && $output->isVerbose()) {
            $timings = $analysis['timings'];
            uasort($timings, static fn (array $left, array $right): int => $right['execution_time_ms'] <=> $left['execution_time_ms']);
            $io->section('Analyzer timings');
            $io->table(['Analyzer', 'Findings', 'Time'], array_map(
                static fn (string $class, array $stats): array => [substr((string) strrchr('\\' . $class, '\\'), 1), $stats['issues_found'], sprintf('%.2f ms', $stats['execution_time_ms'])],
                array_keys($timings),
                array_values($timings),
            ));
        }

        $this->renderFindings($io, $findings);

        if ($unmatched > 0) {
            $io->note(sprintf('%d baseline entries no longer match a finding: regenerate the baseline with --generate-baseline.', $unmatched));
        }

        if (false !== getenv('GITHUB_ACTIONS') && 'true' === getenv('GITHUB_ACTIONS')) {
            $this->renderGithub($output, $findings);
        }
    }

    /**
     * GitHub workflow commands: each finding becomes an annotation on the file and line of the pull request.
     *
     * @param list<array<string, mixed>> $findings
     */
    private function renderGithub(OutputInterface $output, array $findings): void
    {
        $escape = static fn (string $value, bool $property): string => strtr($value, ['%' => '%25', "\r" => '%0D', "\n" => '%0A'] + ($property ? [':' => '%3A', ',' => '%2C'] : []));

        foreach ($findings as $finding) {
            $level = match ($finding['severity']) {
                'critical' => 'error',
                'warning' => 'warning',
                default => 'notice',
            };
            $properties = [];
            if (null !== $finding['file']) {
                $properties[] = 'file=' . $escape((string) $finding['file'], true);
                if (null !== $finding['line']) {
                    $properties[] = 'line=' . $finding['line'];
                }
            }
            $properties[] = 'title=' . $escape('Doctrine Doctor: ' . $finding['title'], true);
            $message = $finding['message'] . "\n" . sprintf('php bin/console doctrine:doctor:analyze --show-suggestion=%s', $finding['id']);
            $output->writeln(sprintf('::%s %s::%s', $level, implode(',', $properties), $escape($message, false)), OutputInterface::OUTPUT_RAW);
        }
    }

    /**
     * The fix in one line, and how to print it in full.
     *
     * @param array<string, mixed> $finding
     */
    private static function tip(array $finding): string
    {
        $issue = $finding['issue'] ?? null;
        $title = $issue instanceof IssueInterface ? $issue->getSuggestion()?->getMetadata()->title : null;

        return sprintf('💡 %s<fg=gray>--show-suggestion=%s</>', null !== $title && '' !== $title ? $title . ': ' : '', (string) $finding['id']);
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private function renderSummary(SymfonyStyle $io, int $analyzerCount, array $findings, float $durationMs, int $ignored): void
    {
        $counts = $this->totals($findings);

        $io->definitionList(
            ['Analyzers' => $analyzerCount],
            ['Findings' => count($findings) . ($ignored > 0 ? sprintf(' (%d ignored by the baseline)', $ignored) : '')],
            ['Duration' => sprintf('%.2f ms', $durationMs)],
        );
        $io->text(sprintf(
            '<fg=red;options=bold>%d critical</>  <fg=yellow;options=bold>%d warnings</>  <fg=blue;options=bold>%d info</>',
            $counts['critical'],
            $counts['warning'],
            $counts['info'],
        ));
    }

    /**
     * PHPStan-like report: one table per file, findings ordered by line, with the reason and the command
     * that prints the suggested fix.
     *
     * @param list<array<string, mixed>> $findings
     */
    private function renderFindings(SymfonyStyle $io, array $findings): void
    {
        if ([] === $findings) {
            $io->success('No findings detected.');

            return;
        }

        $byFile = [];
        foreach ($findings as $finding) {
            $byFile[$finding['file'] ?? 'Configuration and database'][] = $finding;
        }
        ksort($byFile);

        $marks = ['critical' => '<fg=red;options=bold>critical</>', 'warning' => '<fg=yellow;options=bold>warning</>', 'info' => '<fg=blue>info</>'];
        foreach ($byFile as $file => $rows) {
            usort($rows, static fn (array $left, array $right): int => [$left['line'] ?? 0, $left['title']] <=> [$right['line'] ?? 0, $right['title']]);
            $io->table(['Line', (string) $file], array_map(static fn (array $finding): array => [
                $finding['line'] ?? '',
                sprintf(
                    "%s  %s\n%s\n<fg=gray>🪪  %s</>\n%s",
                    $marks[$finding['severity']] ?? $finding['severity'],
                    $finding['title'],
                    wordwrap((string) $finding['message'], 100),
                    implode(', ', array_filter([$finding['type'], self::shortAnchor((string) $finding['anchor'])])),
                    self::tip($finding),
                ),
            ], $rows));
        }

        $totals = $this->totals($findings);
        $message = sprintf('Found %d findings: %d critical, %d warnings, %d info. Show a fix with --show-suggestion=<id>.', count($findings), $totals['critical'], $totals['warning'], $totals['info']);
        $totals['critical'] + $totals['warning'] > 0 ? $io->error($message) : $io->note($message);
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private function renderSuggestion(SymfonyStyle $io, array $findings, string $requestedId): void
    {
        $matches = array_values(array_filter($findings, static fn (array $finding): bool => $requestedId === $finding['id']));

        if ([] === $matches) {
            $io->error(sprintf('Unknown issue ID "%s". Available IDs: %s', $requestedId, implode(', ', array_column($findings, 'id'))));

            return;
        }

        foreach (array_column($matches, 'issue') as $issue) {
            $suggestion = $issue->getSuggestion();
            $io->section(sprintf('Suggestion for %s: %s', $requestedId, $issue->getTitle()));

            if (null === $suggestion) {
                $io->warning('This finding does not provide a suggestion.');

                continue;
            }

            $io->text($suggestion->getDescription());
            $io->text('<fg=cyan;options=bold>Suggested fix</>');
            $io->text($this->formatSuggestionCode($suggestion->getCode()));
        }
    }

    private function formatSuggestionCode(string $code): string
    {
        $code = preg_replace('/<\\/(p|div|h[1-6]|li)>/i', "\n", $code) ?? $code;
        $code = html_entity_decode(strip_tags($code), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\n{3,}/', "\n\n", $code) ?? $code);
    }

    /**
     * Identifies a finding by what it is and where it is, never by its description: descriptions may quote
     * environment values (credentials, absolute paths, timings) that differ between a laptop and CI.
     */
    private function issueId(IssueInterface $issue, FindingLocation $location): string
    {
        return substr(hash('sha256', implode("\0", [
            $issue->getType(),
            $issue->getTitle(),
            $location->anchor,
        ])), 0, 10);
    }

    private static function shortAnchor(string $anchor): string
    {
        return '' === $anchor || str_contains($anchor, '/') ? '' : (string) preg_replace('/^(?:[\\w]+\\\\)+/', '', $anchor);
    }

    private function firstSentences(string $description, int $max = 220): string
    {
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $end = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, ': '));

        return $end > 60 ? mb_substr($cut, 0, $end + 1) : rtrim($cut) . '...';
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return array{critical: int, warning: int, info: int}
     */
    private function totals(array $findings): array
    {
        $severities = array_column($findings, 'severity');

        return [
            'critical' => count(array_keys($severities, 'critical', true)),
            'warning' => count(array_keys($severities, 'warning', true)),
            'info' => count(array_keys($severities, 'info', true)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private function writeBaseline(string $file, array $findings): void
    {
        $entries = array_map(static fn (array $finding): array => ['id' => $finding['id'], 'severity' => $finding['severity'], 'type' => $finding['type'], 'title' => $finding['title'], 'file' => $finding['file']], $findings);
        usort($entries, static fn (array $left, array $right): int => [$left['file'] ?? '', $left['id']] <=> [$right['file'] ?? '', $right['id']]);

        file_put_contents($file, json_encode(['doctrine_doctor_baseline' => 1, 'findings' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /**
     * @return array<string, true>|null
     */
    private function readBaseline(string $file): ?array
    {
        $content = is_file($file) ? file_get_contents($file) : false;
        $data = false !== $content ? json_decode($content, true) : null;
        if (!\is_array($data) || !isset($data['findings']) || !\is_array($data['findings'])) {
            return null;
        }

        $ids = [];
        foreach ($data['findings'] as $entry) {
            if (\is_array($entry) && isset($entry['id']) && \is_string($entry['id'])) {
                $ids[$entry['id']] = true;
            }
        }

        return $ids;
    }

    /**
     * @return array{issues: list<IssueInterface>, errors: list<string>, timings: array<string, array{issues_found: int, execution_time_ms: float}>, count: int, duration_ms: float}
     */
    private function runStaticAnalyzers(bool $includeDatabaseAudits): array
    {
        $issues = [];
        $errors = [];
        $timings = [];
        $count = 0;
        $startedAt = hrtime(true);

        foreach ($this->analyzers as $analyzer) {
            if (!$analyzer instanceof StaticAnalyzerInterface || ($analyzer instanceof DatabaseAuditAnalyzerInterface && !$includeDatabaseAudits)) {
                continue;
            }

            ++$count;
            $analyzerStartedAt = hrtime(true);
            $issueCount = 0;

            try {
                foreach ($analyzer->analyze(QueryDataCollection::empty()) as $issue) {
                    $issues[] = $issue;
                    ++$issueCount;
                }
            } catch (\Throwable $throwable) {
                $errors[] = sprintf('%s: %s', $analyzer::class, $throwable->getMessage());
            } finally {
                $timings[$analyzer::class] = [
                    'issues_found' => $issueCount,
                    'execution_time_ms' => round((hrtime(true) - $analyzerStartedAt) / 1_000_000, 2),
                ];
            }
        }

        return [
            'issues' => $issues,
            'errors' => $errors,
            'timings' => $timings,
            'count' => $count,
            'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000,
        ];
    }
}
