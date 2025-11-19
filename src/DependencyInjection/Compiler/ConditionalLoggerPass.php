<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Replaces logger injections with NullLogger when internal_logging is disabled.
 *
 * This eliminates ~133ms of logging overhead for production users.
 * Contributors can enable logging via doctrine_doctor.debug.internal_logging: true
 */
final class ConditionalLoggerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Check if internal logging is enabled (defaults to false)
        $internalLoggingEnabled = $container->hasParameter('doctrine_doctor.debug.internal_logging')
            && true === $container->getParameter('doctrine_doctor.debug.internal_logging');

        if ($internalLoggingEnabled) {
            // Logging enabled - keep the real logger injections
            return;
        }

        // Replace all optional logger injections with NullLogger for Doctrine Doctor services
        $nullLoggerRef = new Reference('doctrine_doctor.null_logger');

        foreach ($container->findTaggedServiceIds('doctrine_doctor.analyzer') as $id => $tags) {
            if (!$container->hasDefinition($id)) {
                continue;
            }

            $definition = $container->getDefinition($id);
            $arguments = $definition->getArguments();

            // Replace $logger argument with NullLogger (check both positional and named)
            foreach ($arguments as $key => $value) {
                if ($value instanceof Reference && 'logger' === (string) $value) {
                    $definition->setArgument($key, $nullLoggerRef);
                }
            }

            // Also check named arguments using array_key_exists
            if (array_key_exists('$logger', $arguments)) {
                $definition->setArgument('$logger', $nullLoggerRef);
            }
        }

        // Also replace in helper services
        $helperServices = [
            'AhmedBhs\DoctrineDoctor\Collector\Helper\DatabaseInfoCollector',
            'AhmedBhs\DoctrineDoctor\Collector\Helper\DataCollectorLogger',
            'AhmedBhs\DoctrineDoctor\Service\Cache\EntityMetadataCache',
        ];

        foreach ($helperServices as $serviceId) {
            if ($container->hasDefinition($serviceId)) {
                $definition = $container->getDefinition($serviceId);
                $arguments = $definition->getArguments();
                if (array_key_exists('$logger', $arguments)) {
                    $definition->setArgument('$logger', $nullLoggerRef);
                }
            }
        }
    }
}
