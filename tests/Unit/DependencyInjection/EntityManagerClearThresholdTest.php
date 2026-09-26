<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\DependencyInjection;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\EntityManagerClearAnalyzer;
use AhmedBhs\DoctrineDoctor\DependencyInjection\DoctrineDoctorExtension;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Twenty writes in one request, such as an order saved with twenty lines, hold
 * twenty entities in the identity map: no memory risk worth a warning. The
 * default follows the value config/packages/doctrine_doctor_recommended.yaml
 * already describes as realistic for batch processing.
 */
final class EntityManagerClearThresholdTest extends TestCase
{
    #[Test]
    public function it_defaults_the_bundle_threshold_to_fifty(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', \sys_get_temp_dir());
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);

        new DoctrineDoctorExtension()->load([], $container);

        self::assertSame(50, $container->getParameter('doctrine_doctor.analyzers.entity_manager_clear.batch_size_threshold'));
    }

    #[Test]
    public function it_does_not_warn_about_twenty_writes_by_default(): void
    {
        $analyzer = new EntityManagerClearAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );

        self::assertCount(0, $analyzer->analyze($this->ormInserts(20)));
        self::assertCount(1, $analyzer->analyze($this->ormInserts(50)));
    }

    private function ormInserts(int $count): \AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection
    {
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= $count; ++$i) {
            $queries->addQueryWithOrmBacktrace("INSERT INTO order_lines (label) VALUES ('Line {$i}')", 0.002);
        }

        return $queries->build();
    }
}
