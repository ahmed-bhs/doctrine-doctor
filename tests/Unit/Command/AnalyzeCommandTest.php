<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Command;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\DatabaseAuditAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\StaticAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Command\AnalyzeCommand;
use AhmedBhs\DoctrineDoctor\Issue\ConfigurationIssue;
use AhmedBhs\DoctrineDoctor\Service\IssueDeduplicator;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AnalyzeCommandTest extends TestCase
{
    #[Test]
    public function it_runs_static_analyzers_and_skips_runtime_and_database_audits_by_default(): void
    {
        $staticAnalyzer = new class() implements StaticAnalyzerInterface {
            public int $calls = 0;

            public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
            {
                ++$this->calls;

                return IssueCollection::fromArray([]);
            }
        };
        $databaseAnalyzer = new class() implements DatabaseAuditAnalyzerInterface {
            public int $calls = 0;

            public function analyzeMetadata(): IssueCollection
            {
                return IssueCollection::fromArray([]);
            }

            public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
            {
                ++$this->calls;

                return IssueCollection::fromArray([]);
            }
        };
        $runtimeAnalyzer = new class() implements AnalyzerInterface {
            public int $calls = 0;

            public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
            {
                ++$this->calls;

                return IssueCollection::fromArray([]);
            }
        };
        $commandTester = new CommandTester(new AnalyzeCommand(
            [$staticAnalyzer, $databaseAnalyzer, $runtimeAnalyzer],
            new IssueDeduplicator(),
        ));

        $status = $commandTester->execute([]);

        self::assertSame(0, $status);
        self::assertSame(1, $staticAnalyzer->calls);
        self::assertSame(0, $databaseAnalyzer->calls);
        self::assertSame(0, $runtimeAnalyzer->calls);
        self::assertStringContainsString('Analyzers', $commandTester->getDisplay());
    }

    #[Test]
    public function it_runs_database_audits_when_requested(): void
    {
        $databaseAnalyzer = new class() implements DatabaseAuditAnalyzerInterface {
            public int $calls = 0;

            public function analyzeMetadata(): IssueCollection
            {
                return IssueCollection::fromArray([]);
            }

            public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
            {
                ++$this->calls;

                return IssueCollection::fromArray([]);
            }
        };
        $commandTester = new CommandTester(new AnalyzeCommand([$databaseAnalyzer], new IssueDeduplicator()));

        $status = $commandTester->execute(['--with-database' => true]);

        self::assertSame(0, $status);
        self::assertSame(1, $databaseAnalyzer->calls);
        self::assertStringContainsString('Analyzers', $commandTester->getDisplay());
    }

    #[Test]
    public function it_fails_at_the_requested_severity_threshold(): void
    {
        $analyzer = new class() implements StaticAnalyzerInterface {
            public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
            {
                return IssueCollection::fromArray([
                    new ConfigurationIssue([
                        'type' => 'ci_warning',
                        'title' => 'Configuration warning',
                        'description' => 'A warning found during CI analysis.',
                        'severity' => 'warning',
                        'queries' => [],
                    ]),
                ]);
            }
        };
        $command = new AnalyzeCommand([$analyzer], new IssueDeduplicator());
        $commandTester = new CommandTester($command);

        self::assertSame(1, $commandTester->execute([]));
        self::assertSame(0, $commandTester->execute(['--fail-on' => 'critical']));
        self::assertSame(0, $commandTester->execute(['--fail-on' => 'never']));
        self::assertSame(2, $commandTester->execute(['--fail-on' => 'unknown']));
    }

    #[Test]
    public function it_prints_actionable_issue_details(): void
    {
        $analyzer = new class() implements StaticAnalyzerInterface {
            public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
            {
                return IssueCollection::fromArray([
                    new ConfigurationIssue([
                        'type' => 'ci_warning',
                        'title' => 'Configuration warning',
                        'description' => "Enable the metadata cache in production configuration.\\n\\nThis prevents Doctrine from reparsing entity metadata on every request.",
                        'severity' => 'warning',
                        'entity' => 'App\\Entity\\Order',
                        'field' => 'total',
                        'queries' => [],
                    ]),
                ]);
            }
        };

        $commandTester = new CommandTester(new AnalyzeCommand([$analyzer], new IssueDeduplicator()));

        $commandTester->execute(['--fail-on' => 'never']);

        $display = (string) preg_replace('/\\s+/', ' ', $commandTester->getDisplay());
        self::assertStringContainsString('Order::$total', $display);
        self::assertStringContainsString('Enable the metadata cache in production configuration.', $display);
        self::assertStringContainsString('This', $display);
        self::assertStringContainsString('prevents Doctrine from reparsing entity metadata on every request.', $display);

        preg_match('/--show-suggestion=([a-f0-9]{10})/', $commandTester->getDisplay(), $matches);
        self::assertNotSame([], $matches);
        $commandTester->execute(['--fail-on' => 'never', '--show-suggestion' => $matches[1]]);

        self::assertStringContainsString('does not provide a suggestion', $commandTester->getDisplay());
    }

    #[Test]
    public function it_prints_a_suggestion_description_once(): void
    {
        $analyzer = new class() implements StaticAnalyzerInterface {
            public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
            {
                $issue = new ConfigurationIssue([
                    'type' => 'ci_warning',
                    'title' => 'Configuration warning',
                    'description' => 'Something to change.',
                    'severity' => 'warning',
                    'queries' => [],
                ]);
                $issue->setSuggestion(PlatformAnalyzerTestHelper::createSuggestionFactory()->createFromTemplate(
                    'Integrity/code_suggestion',
                    ['title' => 'Change it', 'description' => 'Pass every value as a parameter.', 'code' => '$a = 1;'],
                    new SuggestionMetadata(type: SuggestionType::integrity(), severity: Severity::warning(), title: 'Change it'),
                ));

                return IssueCollection::fromArray([$issue]);
            }
        };
        $commandTester = new CommandTester(new AnalyzeCommand([$analyzer], new IssueDeduplicator()));
        $commandTester->execute(['--fail-on' => 'never']);
        preg_match('/--show-suggestion=([a-f0-9]{10})/', $commandTester->getDisplay(), $matches);

        $commandTester->execute(['--fail-on' => 'never', '--show-suggestion' => $matches[1]]);

        self::assertSame(1, substr_count($commandTester->getDisplay(), 'Pass every value as a parameter.'));
        self::assertStringContainsString('$a = 1;', $commandTester->getDisplay());
    }
}
