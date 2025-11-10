<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Detector;

class PatternDetector implements DetectorInterface
{
    public function __construct(
        /**
         * @readonly
         */
        private string $pattern,
    ) {
    }

    /**
     * @param array<array{sql: string}> $queries
     * @return array<array{sql: string}>
     */
    public function detect(array $queries): array
    {
        $detected = [];

        assert(is_iterable($queries), '$queries must be iterable');

        foreach ($queries as $query) {
            assert(is_array($query) && isset($query['sql']), 'query must be array with sql key');
            if (1 === preg_match($this->pattern, $query['sql'])) {
                $detected[] = $query;
            }
        }

        return $detected;
    }
}
