<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\AiMate\Formatter;

use AhmedBhs\DoctrineDoctor\AiMate\DoctrineDoctorMcpSanitizer;
use AhmedBhs\DoctrineDoctor\Collector\AnalysisResultStore;
use AhmedBhs\DoctrineDoctor\Collector\DoctrineDoctorDataCollector;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\CollectorFormatterInterface;
use Symfony\Component\HttpKernel\DataCollector\DataCollectorInterface;

/**
 * @implements CollectorFormatterInterface<DoctrineDoctorDataCollector>
 */
final readonly class DoctrineDoctorCollectorFormatter implements CollectorFormatterInterface
{
    public function __construct(
        private DoctrineDoctorMcpSanitizer $sanitizer,
        private ?AnalysisResultStore $resultStore = null,
    ) {
    }

    public function getName(): string
    {
        return 'doctrine_doctor';
    }

    /**
     * @return array<string, mixed>
     */
    public function format(DataCollectorInterface $collector): array
    {
        if (!$collector instanceof DoctrineDoctorDataCollector) {
            return ['error' => 'Invalid doctrine_doctor collector'];
        }

        $collector->resolvePendingAnalysis($this->resultStore);

        if ($collector->isAnalysisPending()) {
            return [
                'analysis_pending' => true,
                'hint' => 'The queries of this request have not been analyzed yet. Call the doctrine-doctor-issues tool '
                    . 'with this profile token: it runs the analysis in the application first.',
                'stats' => $collector->getStats(),
                'issues' => [],
            ];
        }

        return [
            'stats' => $collector->getStats(),
            'database_info' => $collector->getDatabaseInfo(),
            'profiler_overhead' => $collector->getProfilerOverhead(),
            'issues' => $this->sanitizer->sanitizeIssues(
                array_values($collector->getIssues()),
                limit: 100,
                includeQueries: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummary(DataCollectorInterface $collector): array
    {
        if (!$collector instanceof DoctrineDoctorDataCollector) {
            return ['error' => 'Invalid doctrine_doctor collector'];
        }

        $collector->resolvePendingAnalysis($this->resultStore);

        if ($collector->isAnalysisPending()) {
            return ['analysis_pending' => true] + $collector->getStats();
        }

        return $collector->getStats();
    }
}
