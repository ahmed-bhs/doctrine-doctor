<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Factory;

use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Factory\IssueTypeRegistryInterface;
use AhmedBhs\DoctrineDoctor\Issue\NPlusOneIssue;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\Issue\TransactionIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IssueFactoryTest extends TestCase
{
    #[Test]
    public function it_creates_issue_from_enum_type_mapping(): void
    {
        $factory = new IssueFactory();

        $issue = $factory->createFromArray([
            'type' => IssueType::N_PLUS_ONE->value,
            'title' => 'N+1 Query Detected',
            'description' => 'Detected query repetition',
            'severity' => 'warning',
        ]);

        self::assertInstanceOf(NPlusOneIssue::class, $issue);
    }

    #[Test]
    public function it_creates_issue_from_transaction_type_mapping(): void
    {
        $factory = new IssueFactory();

        $issue = $factory->createFromArray([
            'type' => IssueType::TRANSACTION_BOUNDARY->value,
            'title' => 'Transaction Boundary',
            'description' => 'Transaction scope is inconsistent',
            'severity' => 'warning',
        ]);

        self::assertInstanceOf(TransactionIssue::class, $issue);
    }

    #[Test]
    public function it_exposes_supported_types_from_dynamic_registry(): void
    {
        $factory = new IssueFactory();
        $supportedTypes = $factory->getSupportedTypes();

        self::assertContains(IssueType::N_PLUS_ONE->value, $supportedTypes);
        self::assertContains(IssueType::TRANSACTION_BOUNDARY->value, $supportedTypes);
        self::assertContains(IssueType::SET_MAX_RESULTS_WITH_COLLECTION_JOIN->value, $supportedTypes);
    }

    #[Test]
    public function it_uses_injected_registry_for_mapping(): void
    {
        $registry = new class() implements IssueTypeRegistryInterface {
            public function getTypeMap(): array
            {
                return ['custom_performance' => PerformanceIssue::class];
            }

            public function getSupportedTypes(): array
            {
                return ['custom_performance'];
            }

            public function supports(string $type): bool
            {
                return 'custom_performance' === $type;
            }
        };

        $factory = new IssueFactory($registry);
        $issue = $factory->createFromArray([
            'type' => 'custom_performance',
            'title' => 'Custom issue',
            'description' => 'Uses custom registry',
            'severity' => 'warning',
        ]);

        self::assertInstanceOf(PerformanceIssue::class, $issue);
        self::assertTrue($factory->supports('custom_performance'));
        self::assertSame(['custom_performance'], $factory->getSupportedTypes());
    }
}
