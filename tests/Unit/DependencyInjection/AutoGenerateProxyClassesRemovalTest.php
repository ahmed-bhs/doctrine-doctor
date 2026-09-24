<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\DependencyInjection;

use AhmedBhs\DoctrineDoctor\DependencyInjection\Configuration;
use AhmedBhs\DoctrineDoctor\DependencyInjection\DoctrineDoctorExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * DoctrineBundle 3.0 removed doctrine.orm.auto_generate_proxy_classes, and the
 * bundle requires DoctrineBundle ^3.0: an application declaring the option no
 * longer boots, so the analyzer reading it from YAML could never report anything.
 * Its configuration node stays accepted, deprecated, so existing configurations
 * keep booting.
 */
final class AutoGenerateProxyClassesRemovalTest extends TestCase
{
    #[Test]
    public function it_no_longer_tags_the_config_file_proxy_analyzer(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', \sys_get_temp_dir());
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);

        new DoctrineDoctorExtension()->load([], $container);

        $taggedServiceIds = \array_keys($container->findTaggedServiceIds('doctrine_doctor.analyzer'));

        self::assertNotContains('AhmedBhs\DoctrineDoctor\Analyzer\Configuration\AutoGenerateProxyClassesAnalyzer', $taggedServiceIds);
    }

    #[Test]
    public function it_still_accepts_the_legacy_configuration_key_as_deprecated(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, \E_USER_DEPRECATED);

        try {
            new Processor()->processConfiguration(new Configuration(), [[
                'analyzers' => ['auto_generate_proxy_classes' => ['enabled' => false]],
            ]]);
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, array_filter(
            $deprecations,
            static fn (string $message): bool => str_contains($message, 'auto_generate_proxy_classes'),
        ));
    }
}
