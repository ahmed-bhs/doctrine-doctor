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

    private const array ORM_ONLY_ANALYZERS = [
        'AhmedBhs\\DoctrineDoctor\\Analyzer\\Integrity\\PartialObjectAnalyzer',
    ];

    public function process(ContainerBuilder $container): void
    {
        if ($container->has('doctrine.orm.entity_manager')) {
            return;
        }

        $this->removeOrmInstanceofBindings($container);

        foreach (self::CORE_ORM_SERVICES as $serviceId) {
            $this->removeService($container, $serviceId);
        }

        foreach (self::ORM_ONLY_ANALYZERS as $serviceId) {
            $this->removeService($container, $serviceId);
        }

        $maxPasses = 5;
        for ($pass = 0; $pass < $maxPasses; ++$pass) {
            $removed = false;

            foreach (array_keys($container->getDefinitions()) as $serviceId) {
                if (!is_string($serviceId)) {
                    continue;
                }
                if (!str_starts_with($serviceId, 'AhmedBhs\\DoctrineDoctor\\')) {
                    continue;
                }
                if (!$container->hasDefinition($serviceId)) {
                    continue;
                }
                if ('AhmedBhs\\DoctrineDoctor\\Collector\\DoctrineDoctorDataCollector' === $serviceId) {
                    continue;
                }
                if (str_starts_with($serviceId, 'AhmedBhs\\DoctrineDoctor\\Collector\\')) {
                    continue;
                }
                if (str_starts_with($serviceId, 'AhmedBhs\\DoctrineDoctor\\Factory\\')) {
                    continue;
                }
                if (str_starts_with($serviceId, 'AhmedBhs\\DoctrineDoctor\\Service\\')) {
                    continue;
                }

                $definition = $container->getDefinition($serviceId);
                if ($this->dependsOnEntityManager($definition)
                    || $this->dependsOnRemovedService($container, $definition)) {
                    $this->removeService($container, $serviceId);
                    $removed = true;
                }
            }

            if (!$removed) {
                break;
            }
        }
    }

    private function removeOrmInstanceofBindings(ContainerBuilder $container): void
    {
        foreach ($container->getAutoconfiguredInstanceof() as $definition) {
            $bindings = $definition->getBindings();
            $changed = false;
            foreach (array_keys($bindings) as $key) {
                if (str_contains((string) $key, 'EntityManager')
                    || str_contains((string) $key, 'entityManager')) {
                    unset($bindings[$key]);
                    $changed = true;
                }
            }
            if ($changed) {
                $definition->setBindings($bindings);
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

    private function dependsOnRemovedService(ContainerBuilder $container, Definition $definition): bool
    {
        foreach ($definition->getArguments() as $arg) {
            if ($this->referenceTargetsRemovedService($container, $arg)) {
                return true;
            }
        }

        foreach ($definition->getMethodCalls() as $call) {
            foreach ($call[1] ?? [] as $arg) {
                if ($this->referenceTargetsRemovedService($container, $arg)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function referenceTargetsRemovedService(ContainerBuilder $container, mixed $value): bool
    {
        if ($value instanceof Reference) {
            $target = (string) $value;
            if (!str_starts_with($target, 'AhmedBhs\\DoctrineDoctor\\')) {
                return false;
            }
            $behavior = $value->getInvalidBehavior();
            if (\Symfony\Component\DependencyInjection\ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE !== $behavior) {
                return false;
            }
            return !$container->has($target);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->referenceTargetsRemovedService($container, $item)) {
                    return true;
                }
            }
        }

        return false;
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

        $class = $definition->getClass();
        if (null === $class || !class_exists($class)) {
            return false;
        }

        try {
            $reflection = new \ReflectionClass($class);
        } catch (\ReflectionException) {
            return false;
        }

        $constructor = $reflection->getConstructor();
        if (null === $constructor) {
            return false;
        }

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }
            $typeName = $type->getName();
            if ('Doctrine\\ORM\\EntityManagerInterface' === $typeName
                || 'Doctrine\\ORM\\EntityManager' === $typeName) {
                return true;
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
