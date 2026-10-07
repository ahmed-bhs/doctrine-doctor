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

/**
 * A stored profile collected with AnalysisTiming::OnView and never analyzed.
 */
final class FixturePendingDoctrineDoctorCollector extends DoctrineDoctorDataCollector
{
    public function __construct()
    {
        $this->data = ['enabled' => true, 'analysis_pending' => true];
    }

    public function getName(): string
    {
        return 'doctrine_doctor';
    }

    public function getStats(): array
    {
        return ['total' => 0, 'critical' => 0];
    }

    public function getIssues(): array
    {
        return [];
    }
}
