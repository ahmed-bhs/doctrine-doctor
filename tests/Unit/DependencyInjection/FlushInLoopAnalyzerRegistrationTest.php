<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\DependencyInjection;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\FlushInLoopAnalyzer;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\FlushInLoopAnalyzerModern;
use AhmedBhs\DoctrineDoctor\DependencyInjection\DoctrineDoctorExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * FlushInLoopAnalyzerModern is a draft/comparison variant of FlushInLoopAnalyzer
 * (see its own docblock: "Compare this with the original... to see the benefits").
 * The Performance/* glob auto-registration previously tagged both as
 * doctrine_doctor.analyzer, so every flush-in-loop finding fired twice with two
 * different boundary heuristics (legacy: write->non-id-select only; modern: also
 * splits on backtrace-frame change), producing diverging flush_count values for
 * the same request. Only the legacy analyzer should be tagged.
 */
final class FlushInLoopAnalyzerRegistrationTest extends TestCase
{
    #[Test]
    public function only_legacy_flush_in_loop_analyzer_is_tagged(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', \sys_get_temp_dir());
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);

        $extension = new DoctrineDoctorExtension();
        $extension->load([], $container);

        $taggedServiceIds = \array_keys($container->findTaggedServiceIds('doctrine_doctor.analyzer'));

        self::assertContains(FlushInLoopAnalyzer::class, $taggedServiceIds);
        self::assertNotContains(FlushInLoopAnalyzerModern::class, $taggedServiceIds);
    }
}
