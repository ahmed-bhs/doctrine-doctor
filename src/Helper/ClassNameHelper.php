<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Helper;

final class ClassNameHelper
{
    private function __construct()
    {
    }

    public static function shortName(string $className): string
    {
        $parts = explode('\\', $className);

        return (string) end($parts);
    }
}
