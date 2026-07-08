<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\AiMate;

use AhmedBhs\DoctrineDoctor\Collector\DoctrineDoctorDataCollector;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;

final class FixtureDoctrineDoctorCollector extends DoctrineDoctorDataCollector
{
    public function __construct()
    {
        $this->data = [];
    }

    public function getName(): string
    {
        return 'doctrine_doctor';
    }

    public function getStats(): array
    {
        return ['total' => 1, 'critical' => 1];
    }

    public function getIssues(): array
    {
        return [
            new PerformanceIssue([
                'type' => 'slow_query',
                'title' => 'Slow query',
                'description' => 'A slow query was detected.',
                'severity' => Severity::critical(),
            ]),
        ];
    }
}
