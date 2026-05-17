<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\DependencyInjection\Compiler;

use AhmedBhs\DoctrineDoctor\DependencyInjection\Compiler\RemoveOrmServicesPass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class RemoveOrmServicesPassTest extends TestCase
{
    #[Test]
    public function it_keeps_all_doctrine_doctor_services_when_orm_is_available(): void
    {
        $container = new ContainerBuilder();
        $container->register('doctrine.orm.entity_manager', \stdClass::class);
        $container->register('doctrine_doctor.entity_manager', \stdClass::class);
        $container->register(
            'AhmedBhs\\DoctrineDoctor\\Analyzer\\Performance\\NPlusOneAnalyzer',
            FakeOrmAnalyzer::class,
        )->setArguments([new Reference('doctrine_doctor.entity_manager')]);

        (new RemoveOrmServicesPass())->process($container);

        self::assertTrue($container->has('doctrine_doctor.entity_manager'));
        self::assertTrue($container->has('AhmedBhs\\DoctrineDoctor\\Analyzer\\Performance\\NPlusOneAnalyzer'));
    }

    #[Test]
    public function it_removes_core_orm_services_when_orm_is_missing(): void
    {
        $container = new ContainerBuilder();
        $container->register('doctrine_doctor.entity_manager', \stdClass::class);
        $container->register('doctrine_doctor.entity_manager_with_filtered_metadata', \stdClass::class);
        $container->register('AhmedBhs\\DoctrineDoctor\\Metadata\\EntityMetadataProvider', \stdClass::class);

        (new RemoveOrmServicesPass())->process($container);

        self::assertFalse($container->has('doctrine_doctor.entity_manager'));
        self::assertFalse($container->has('doctrine_doctor.entity_manager_with_filtered_metadata'));
        self::assertFalse($container->has('AhmedBhs\\DoctrineDoctor\\Metadata\\EntityMetadataProvider'));
    }

    #[Test]
    public function it_removes_analyzers_with_entity_manager_argument_reference(): void
    {
        $container = new ContainerBuilder();
        $container->register('doctrine_doctor.entity_manager', \stdClass::class);
        $container->register(
            'AhmedBhs\\DoctrineDoctor\\Analyzer\\Performance\\NPlusOneAnalyzer',
            FakeOrmAnalyzer::class,
        )->setArguments([new Reference('doctrine_doctor.entity_manager')]);

        (new RemoveOrmServicesPass())->process($container);

        self::assertFalse($container->has('AhmedBhs\\DoctrineDoctor\\Analyzer\\Performance\\NPlusOneAnalyzer'));
    }

    #[Test]
    public function it_removes_analyzers_with_entity_manager_constructor_typehint(): void
    {
        $container = new ContainerBuilder();
        $container->register(
            'AhmedBhs\\DoctrineDoctor\\Analyzer\\Integrity\\JoinTypeConsistencyAnalyzer',
            FakeTypedOrmAnalyzer::class,
        );

        (new RemoveOrmServicesPass())->process($container);

        self::assertFalse(
            $container->has('AhmedBhs\\DoctrineDoctor\\Analyzer\\Integrity\\JoinTypeConsistencyAnalyzer'),
        );
    }

    #[Test]
    public function it_removes_analyzers_that_depend_on_already_removed_services(): void
    {
        $container = new ContainerBuilder();
        $container->register('doctrine_doctor.entity_manager', \stdClass::class);
        $container->register(
            'AhmedBhs\\DoctrineDoctor\\Analyzer\\Helper\\CollectionJoinDetector',
            FakeOrmAnalyzer::class,
        )->setArguments([new Reference('doctrine_doctor.entity_manager')]);
        $container->register(
            'AhmedBhs\\DoctrineDoctor\\Analyzer\\Performance\\SetMaxResultsWithCollectionJoinAnalyzer',
            FakeOrmAnalyzer::class,
        )->setArguments([
            new Reference('AhmedBhs\\DoctrineDoctor\\Analyzer\\Helper\\CollectionJoinDetector'),
        ]);

        (new RemoveOrmServicesPass())->process($container);

        self::assertFalse(
            $container->has('AhmedBhs\\DoctrineDoctor\\Analyzer\\Performance\\SetMaxResultsWithCollectionJoinAnalyzer'),
            'Analyzers depending on a removed ORM helper should also be removed.',
        );
    }

    #[Test]
    public function it_explicitly_removes_orm_only_analyzers_even_without_em_arg(): void
    {
        $container = new ContainerBuilder();
        $container->register(
            'AhmedBhs\\DoctrineDoctor\\Analyzer\\Integrity\\PartialObjectAnalyzer',
            FakeOrmAnalyzer::class,
        );

        (new RemoveOrmServicesPass())->process($container);

        self::assertFalse(
            $container->has('AhmedBhs\\DoctrineDoctor\\Analyzer\\Integrity\\PartialObjectAnalyzer'),
            'PartialObjectAnalyzer is ORM-only because its recommendations rely on DQL syntax.',
        );
    }

    #[Test]
    public function it_preserves_collector_factory_and_service_namespaces(): void
    {
        $container = new ContainerBuilder();
        $container->register(
            'AhmedBhs\\DoctrineDoctor\\Collector\\DoctrineDoctorDataCollector',
            FakeOrmAnalyzer::class,
        );
        $container->register(
            'AhmedBhs\\DoctrineDoctor\\Factory\\IssueFactory',
            FakeOrmAnalyzer::class,
        );
        $container->register(
            'AhmedBhs\\DoctrineDoctor\\Service\\IssueDeduplicator',
            FakeOrmAnalyzer::class,
        );

        (new RemoveOrmServicesPass())->process($container);

        self::assertTrue($container->has('AhmedBhs\\DoctrineDoctor\\Collector\\DoctrineDoctorDataCollector'));
        self::assertTrue($container->has('AhmedBhs\\DoctrineDoctor\\Factory\\IssueFactory'));
        self::assertTrue($container->has('AhmedBhs\\DoctrineDoctor\\Service\\IssueDeduplicator'));
    }

    #[Test]
    public function it_strips_entity_manager_instanceof_bindings(): void
    {
        $container = new ContainerBuilder();
        $instanceof = new Definition();
        $instanceof->setBindings([
            '$entityManager' => new Reference('doctrine_doctor.entity_manager'),
            'Doctrine\\ORM\\EntityManagerInterface' => new Reference('doctrine_doctor.entity_manager'),
            '$threshold' => 5,
        ]);
        $container->setDefinition(
            FakeOrmAnalyzer::class,
            $instanceof,
        );
        $container->registerForAutoconfiguration(FakeOrmAnalyzer::class)
            ->setBindings($instanceof->getBindings());

        (new RemoveOrmServicesPass())->process($container);

        $remaining = $container->getAutoconfiguredInstanceof()[FakeOrmAnalyzer::class]->getBindings();
        self::assertArrayNotHasKey('$entityManager', $remaining);
        self::assertArrayNotHasKey('Doctrine\\ORM\\EntityManagerInterface', $remaining);
        self::assertArrayHasKey('$threshold', $remaining);
    }
}

final class FakeOrmAnalyzer
{
}

final class FakeTypedOrmAnalyzer
{
    private \Doctrine\ORM\EntityManagerInterface $entityManager;

    public function __construct(\Doctrine\ORM\EntityManagerInterface $em)
    {
        $this->entityManager = $em;
        assert($this->entityManager instanceof \Doctrine\ORM\EntityManagerInterface);
    }
}
