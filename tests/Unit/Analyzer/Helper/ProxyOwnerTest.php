<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Helper;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\ProxyOwner;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\ProxyOwner\Entity\OrderItem;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\ProxyOwner\Entity\Variant;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProxyOwnerTest extends TestCase
{
    #[Test]
    public function it_finds_the_association_from_the_loop_above_the_call(): void
    {
        $owner = ProxyOwner::resolve($this->entityManager(), Variant::class, [['file' => $this->caller(), 'line' => 2]]);

        self::assertSame(['entity' => OrderItem::class, 'field' => 'variant'], $owner);
    }

    #[Test]
    public function it_gives_up_when_two_entities_could_own_the_proxy(): void
    {
        self::assertNull(ProxyOwner::resolve($this->entityManager(), Variant::class, [['file' => $this->caller(), 'line' => 11]]));
    }

    private function caller(): string
    {
        return \dirname(__DIR__, 3) . '/Fixtures/ProxyOwner/caller.txt';
    }

    private function entityManager(): \Doctrine\ORM\EntityManager
    {
        return PlatformAnalyzerTestHelper::createTestEntityManager([\dirname(__DIR__, 3) . '/Fixtures/ProxyOwner/Entity']);
    }
}
