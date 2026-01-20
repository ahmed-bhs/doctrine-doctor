<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Detector;

interface DetectorInterface
{
    /**
     * Detects patterns or issues within a set of queries.
     * @param array<mixed> $queries the queries to analyze
     * @return array an array of detected patterns or issues
     */
    public function detect(array $queries): array;
}
