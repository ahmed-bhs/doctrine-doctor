<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Interface for test data fixtures.
 */
interface FixtureInterface
{
    /**
     * Load realistic test data into the database.
     */
    public function load(EntityManagerInterface $em): void;

    /**
     * Get the loaded entities (useful for assertions in tests).
     *
     * @return array<object>
     */
    public function getLoadedEntities(): array;
}
