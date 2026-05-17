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
use Symfony\Component\DependencyInjection\Reference;

final class OrmServicePrunerLeakTest extends TestCase
{
    #[Test]
    public function it_must_not_prune_doctrine_doctor_service_referencing_unrelated_entity_manager_service_id(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.custom.entity_manager_factory', \stdClass::class);
        $container->register(
            'AhmedBhs\\DoctrineDoctor\\Analyzer\\Custom\\StatelessAnalyzer',
            FakeStatelessAnalyzer::class,
        )->setArguments([new Reference('app.custom.entity_manager_factory')]);

        (new RemoveOrmServicesPass())->process($container);

        self::assertTrue(
            $container->has('AhmedBhs\\DoctrineDoctor\\Analyzer\\Custom\\StatelessAnalyzer'),
            'Pruner leak: substring match "entity_manager" wrongly catches unrelated user-land services. '
            . 'Only true doctrine ORM entity manager references should trigger pruning.',
        );
    }
}

final class FakeStatelessAnalyzer
{
}
