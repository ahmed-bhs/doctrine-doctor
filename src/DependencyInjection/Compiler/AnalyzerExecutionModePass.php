<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Keeps the legacy analyzer tag while assigning each analyzer to one
 * execution path for the runtime profiler or the CI command.
 */
final class AnalyzerExecutionModePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('doctrine_doctor.analyzer') as $serviceId => $_tags) {
            if (!$container->hasDefinition($serviceId)) {
                continue;
            }

            $definition = $container->getDefinition($serviceId);

            if ($definition->hasTag('doctrine_doctor.static_analyzer')) {
                $definition->clearTag('doctrine_doctor.runtime_analyzer');

                continue;
            }

            if (!$definition->hasTag('doctrine_doctor.runtime_analyzer')) {
                $definition->addTag('doctrine_doctor.runtime_analyzer');
            }
        }
    }
}
