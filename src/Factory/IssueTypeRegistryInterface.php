<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Factory;

use AhmedBhs\DoctrineDoctor\Issue\AbstractIssue;

interface IssueTypeRegistryInterface
{
    /**
     * @return array<string, class-string<AbstractIssue>>
     */
    public function getTypeMap(): array;

    /**
     * @return list<string>
     */
    public function getSupportedTypes(): array;

    public function supports(string $type): bool;
}
