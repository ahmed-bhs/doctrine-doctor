<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Factory;

use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;

/**
 * Factory interface for creating Issue instances.
 * Follows Factory Pattern and Dependency Inversion Principle.
 */
interface IssueFactoryInterface
{
    /**
     * Create an issue instance from IssueData.
     */
    public function create(IssueData $issueData): IssueInterface;

    /**
     * Create an issue from legacy array format.
     * @param array<string, mixed> $data
     */
    public function createFromArray(array $data): IssueInterface;
}
