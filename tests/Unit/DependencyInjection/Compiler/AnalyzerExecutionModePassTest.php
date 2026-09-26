<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\DependencyInjection\Compiler;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\StaticAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DependencyInjection\Compiler\AnalyzerExecutionModePass;
use AhmedBhs\DoctrineDoctor\DoctrineDoctorBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class AnalyzerExecutionModePassTest extends TestCase
{
    #[Test]
    public function it_registers_distinct_runtime_and_static_autoconfiguration_tags(): void
    {
        $container = new ContainerBuilder();

        new DoctrineDoctorBundle()->build($container);

        $autoconfiguredInstanceof = $container->getAutoconfiguredInstanceof();

        self::assertSame(
            ['doctrine_doctor.runtime_analyzer' => [[]]],
            $autoconfiguredInstanceof[AnalyzerInterface::class]->getTags(),
        );
        self::assertSame(
            ['doctrine_doctor.static_analyzer' => [[]]],
            $autoconfiguredInstanceof[StaticAnalyzerInterface::class]->getTags(),
        );
    }

    #[Test]
    public function it_keeps_static_analyzers_out_of_the_runtime_tag(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(
            'runtime_analyzer',
            new Definition(\stdClass::class)->addTag('doctrine_doctor.analyzer'),
        );
        $container->setDefinition(
            'static_analyzer',
            new Definition(\stdClass::class)
                ->addTag('doctrine_doctor.analyzer')
                ->addTag('doctrine_doctor.static_analyzer')
                ->addTag('doctrine_doctor.runtime_analyzer'),
        );

        new AnalyzerExecutionModePass()->process($container);

        self::assertTrue($container->getDefinition('runtime_analyzer')->hasTag('doctrine_doctor.runtime_analyzer'));
        self::assertFalse($container->getDefinition('static_analyzer')->hasTag('doctrine_doctor.runtime_analyzer'));
        self::assertTrue($container->getDefinition('static_analyzer')->hasTag('doctrine_doctor.static_analyzer'));
    }

    #[Test]
    public function it_applies_execution_mode_tags_during_container_compilation(): void
    {
        $container = new ContainerBuilder();
        new DoctrineDoctorBundle()->build($container);
        $container->register('runtime_analyzer', RuntimeAnalyzerForExecutionModeTest::class)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->addTag('doctrine_doctor.analyzer');
        $container->register('static_analyzer', StaticAnalyzerForExecutionModeTest::class)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->addTag('doctrine_doctor.analyzer');

        $container->compile(true);

        self::assertTrue($container->getDefinition('runtime_analyzer')->hasTag('doctrine_doctor.runtime_analyzer'));
        self::assertFalse($container->getDefinition('static_analyzer')->hasTag('doctrine_doctor.runtime_analyzer'));
        self::assertTrue($container->getDefinition('static_analyzer')->hasTag('doctrine_doctor.static_analyzer'));
    }
}

final class RuntimeAnalyzerForExecutionModeTest implements AnalyzerInterface
{
    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromArray([]);
    }
}

final class StaticAnalyzerForExecutionModeTest implements StaticAnalyzerInterface
{
    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromArray([]);
    }
}
