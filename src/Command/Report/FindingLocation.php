<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Command\Report;

/**
 * Where a finding points to in the project: a file relative to the project root and a line,
 * plus a stable anchor (class, property or method) used to identify the finding across machines.
 */
final readonly class FindingLocation
{
    public function __construct(
        public ?string $file = null,
        public ?int $line = null,
        public string $anchor = '',
    ) {
    }
}
