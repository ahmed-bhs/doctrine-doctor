<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class RemoveOrmServicesPass implements CompilerPassInterface
{
    private const array CORE_ORM_SERVICES = [
        'doctrine_doctor.entity_manager',
        'doctrine_doctor.entity_manager_with_filtered_metadata',
        'doctrine_doctor.entity_manager.inner',
        'AhmedBhs\\DoctrineDoctor\\Metadata\\EntityMetadataProvider',
        'AhmedBhs\\DoctrineDoctor\\Metadata\\EntityManagerMetadataDecorator',
    ];

    public function process(ContainerBuilder $container): void
    {
        if ($container->has('doctrine.orm.entity_manager')) {
            return;
        }

        foreach (self::CORE_ORM_SERVICES as $serviceId) {
            $this->removeService($container, $serviceId);
        }

        foreach (array_keys($container->getDefinitions()) as $serviceId) {
            if (!is_string($serviceId)) {
                continue;
            }
            if (!str_starts_with($serviceId, 'AhmedBhs\\DoctrineDoctor\\')) {
                continue;
            }

            $definition = $container->getDefinition($serviceId);
            if ($this->dependsOnEntityManager($definition)) {
                $this->removeService($container, $serviceId);
            }
        }
    }

    private function removeService(ContainerBuilder $container, string $serviceId): void
    {
        if ($container->hasDefinition($serviceId)) {
            $container->removeDefinition($serviceId);
        }
        if ($container->hasAlias($serviceId)) {
            $container->removeAlias($serviceId);
        }
    }

    private function dependsOnEntityManager(Definition $definition): bool
    {
        foreach ($definition->getArguments() as $arg) {
            if ($this->referenceTargetsEntityManager($arg)) {
                return true;
            }
        }

        foreach ($definition->getMethodCalls() as $call) {
            foreach ($call[1] ?? [] as $arg) {
                if ($this->referenceTargetsEntityManager($arg)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function referenceTargetsEntityManager(mixed $value): bool
    {
        if ($value instanceof Reference) {
            $target = (string) $value;

            return 'doctrine_doctor.entity_manager' === $target
                || 'doctrine.orm.entity_manager' === $target
                || str_contains($target, 'entity_manager');
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->referenceTargetsEntityManager($item)) {
                    return true;
                }
            }
        }

        return false;
    }
}
