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

        self::assertStringContainsString('Order::$total', $commandTester->getDisplay());
        self::assertStringContainsString('Enable the metadata cache in production configuration.', $commandTester->getDisplay());
        self::assertStringContainsString('This', $commandTester->getDisplay());
        self::assertStringContainsString('prevents Doctrine from reparsing entity metadata on every request.', $commandTester->getDisplay());

        preg_match('/\\s([a-f0-9]{10})\\s+configuration/', $commandTester->getDisplay(), $matches);
        self::assertNotSame([], $matches);
        $commandTester->execute(['--fail-on' => 'never', '--show-suggestion' => $matches[1]]);

        self::assertStringContainsString('does not provide a suggestion', $commandTester->getDisplay());
    }
}
