<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\DependencyInjection;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadeAllAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadePersistOnIndependentEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadeRemoveOnIndependentEntityAnalyzer;
use AhmedBhs\DoctrineDoctor\DependencyInjection\DoctrineDoctorExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The former "unified" CascadeAnalyzer re-implemented the cascade="all",
 * cascade="remove" and cascade="persist" rules of the three dedicated analyzers
 * below. Being picked up by the same Integrity/* glob, it ran alongside them, so
 * every cascade finding was reported twice with the same title.
 */
final class CascadeAnalyzerRegistrationTest extends TestCase
{
    #[Test]
    public function it_tags_a_single_analyzer_per_cascade_rule(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', \sys_get_temp_dir());
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);

        new DoctrineDoctorExtension()->load([], $container);

        $taggedServiceIds = \array_keys($container->findTaggedServiceIds('doctrine_doctor.analyzer'));

        self::assertContains(CascadeAllAnalyzer::class, $taggedServiceIds);
        self::assertContains(CascadePersistOnIndependentEntityAnalyzer::class, $taggedServiceIds);
        self::assertContains(CascadeRemoveOnIndependentEntityAnalyzer::class, $taggedServiceIds);
        self::assertNotContains('AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CascadeAnalyzer', $taggedServiceIds);
    }
}
