<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class OrmServicePruner
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

    private const string SERVICE_NAMESPACE = 'AhmedBhs\\DoctrineDoctor\\';

    private const array PRESERVED_SERVICE_PREFIXES = [
        'AhmedBhs\\DoctrineDoctor\\Collector\\',
        'AhmedBhs\\DoctrineDoctor\\Factory\\',
        'AhmedBhs\\DoctrineDoctor\\Service\\',
    ];

    private const array ENTITY_MANAGER_SERVICE_IDS = [
        'doctrine_doctor.entity_manager',
        'doctrine_doctor.entity_manager.inner',
        'doctrine_doctor.entity_manager_with_filtered_metadata',
        'doctrine.orm.entity_manager',
        'doctrine.orm.default_entity_manager',
    ];

    public function __construct(
        private readonly ContainerBuilder $container,
    ) {
    }

    public function prune(): void
    {
        $this->removeOrmInstanceofBindings();
        $this->removeKnownOrmServices();
        $this->removeDependentServices();
    }

    private function removeOrmInstanceofBindings(): void
    {
        foreach ($this->container->getAutoconfiguredInstanceof() as $definition) {
            $bindings = $definition->getBindings();
            $changed = false;

            foreach (array_keys($bindings) as $key) {
                if (!$this->isEntityManagerBindingKey($key)) {
                    continue;
                }

                unset($bindings[$key]);
                $changed = true;
            }

            if ($changed) {
                $definition->setBindings($bindings);
            }
        }
    }

    private function removeKnownOrmServices(): void
    {
        foreach ([...self::CORE_ORM_SERVICES, ...self::ORM_ONLY_ANALYZERS] as $serviceId) {
            $this->removeService($serviceId);
        }
    }

    private function removeDependentServices(): void
    {
        $maxPasses = 5;

        for ($pass = 0; $pass < $maxPasses; ++$pass) {
            if (!$this->removeSingleDependencyPass()) {
                return;
            }
        }
    }

    private function removeSingleDependencyPass(): bool
    {
        $removed = false;

        foreach (array_keys($this->container->getDefinitions()) as $serviceId) {
            if (!$this->shouldInspectService($serviceId)) {
                continue;
            }

            $definition = $this->container->getDefinition($serviceId);
            if (!$this->shouldRemoveDefinition($definition)) {
                continue;
            }

            $this->removeService($serviceId);
            $removed = true;
        }

        return $removed;
    }

    private function shouldInspectService(mixed $serviceId): bool
    {
        if (!is_string($serviceId) || !str_starts_with($serviceId, self::SERVICE_NAMESPACE)) {
            return false;
        }

        if (!$this->container->hasDefinition($serviceId)) {
            return false;
        }

        if ('AhmedBhs\\DoctrineDoctor\\Collector\\DoctrineDoctorDataCollector' === $serviceId) {
            return false;
        }

        foreach (self::PRESERVED_SERVICE_PREFIXES as $prefix) {
            if (str_starts_with($serviceId, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function shouldRemoveDefinition(Definition $definition): bool
    {
        return $this->dependsOnEntityManager($definition)
            || $this->dependsOnRemovedService($definition);
    }

    private function dependsOnRemovedService(Definition $definition): bool
    {
        foreach ($definition->getArguments() as $argument) {
            if ($this->referenceTargetsRemovedService($argument)) {
                return true;
            }
        }

        foreach ($definition->getMethodCalls() as $call) {
            foreach ($call[1] ?? [] as $argument) {
                if ($this->referenceTargetsRemovedService($argument)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function referenceTargetsRemovedService(mixed $value): bool
    {
        if ($value instanceof Reference) {
            $target = (string) $value;
            if (!str_starts_with($target, self::SERVICE_NAMESPACE)) {
                return false;
            }

            return $this->isStrictReference($value) && !$this->container->has($target);
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if ($this->referenceTargetsRemovedService($item)) {
                return true;
            }
        }

        return false;
    }

    private function dependsOnEntityManager(Definition $definition): bool
    {
        foreach ($definition->getArguments() as $argument) {
            if ($this->referenceTargetsEntityManager($argument)) {
                return true;
            }
        }

        foreach ($definition->getMethodCalls() as $call) {
            foreach ($call[1] ?? [] as $argument) {
                if ($this->referenceTargetsEntityManager($argument)) {
                    return true;
                }
            }
        }

        $class = $definition->getClass();
        if (null === $class) {
            return false;
        }

        try {
            if (!class_exists($class)) {
                return false;
            }

            $reflection  = new \ReflectionClass($class);
            $constructor = $reflection->getConstructor();
        } catch (\Throwable) {
            return true;
        }

        if (null === $constructor) {
            return false;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }

            if ($this->isEntityManagerTypeName($type->getName())) {
                return true;
            }
        }

        return false;
    }

    private function referenceTargetsEntityManager(mixed $value): bool
    {
        if ($value instanceof Reference) {
            return in_array((string) $value, self::ENTITY_MANAGER_SERVICE_IDS, true);
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if ($this->referenceTargetsEntityManager($item)) {
                return true;
            }
        }

        return false;
    }

    private function removeService(string $serviceId): void
    {
        if ($this->container->hasDefinition($serviceId)) {
            $this->container->removeDefinition($serviceId);
        }

        if ($this->container->hasAlias($serviceId)) {
            $this->container->removeAlias($serviceId);
        }
    }

    private function isEntityManagerBindingKey(int|string $key): bool
    {
        return str_contains((string) $key, 'EntityManager')
            || str_contains((string) $key, 'entityManager');
    }

    private function isStrictReference(Reference $reference): bool
    {
        return \Symfony\Component\DependencyInjection\ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE
            === $reference->getInvalidBehavior();
    }

    private function isEntityManagerTypeName(string $typeName): bool
    {
        return 'Doctrine\\ORM\\EntityManagerInterface' === $typeName
            || 'Doctrine\\ORM\\EntityManager' === $typeName;
    }
}
