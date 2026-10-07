<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\AiMate;

/**
 * Completes the deferred analysis of a stored profile from outside the application.
 */
interface PendingAnalysisRunnerInterface
{
    /**
     * @return bool whether the analysis ran and the profile was persisted
     */
    public function run(string $token): bool;
}
