<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector;

use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * Completes the analysis of a stored profile collected with AnalysisTiming::OnView.
 *
 * The stored profile is only read, never written back: a profile loaded from
 * the storage cannot be serialized again (FormDataCollector::__serialize()
 * assumes live data). The result goes to the AnalysisResultStore, where the
 * collector loaded by the profiler (or AI Mate) picks it up.
 */
final readonly class PendingProfileAnalyzer
{
    public function __construct(
        private DoctrineDoctorDataCollector $collector,
        // Profiler::loadProfile() only reads; Profiler::saveProfile() is never used (see above)
        private ?Profiler $profiler,
        private AnalysisResultStore $resultStore,
    ) {
    }

    /**
     * @return bool whether the profile had a pending analysis that was completed
     */
    public function analyze(string $token): bool
    {
        $profile = $this->profiler?->loadProfile($token);

        if (null === $profile || !$profile->hasCollector('doctrine_doctor')) {
            return false;
        }

        $profiled = $profile->getCollector('doctrine_doctor');

        if (!$profiled instanceof DoctrineDoctorDataCollector) {
            return false;
        }

        $key = $profiled->getAnalysisKey();

        if (null === $key || $profiled->resolvePendingAnalysis($this->resultStore) || !$this->collector->completePendingAnalysis($profiled)) {
            return false;
        }

        $this->resultStore->save($key, $profiled->getAnalysisResult());

        return true;
    }
}
