<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\AiMate\Formatter;

use AhmedBhs\DoctrineDoctor\AiMate\DoctrineDoctorMcpSanitizer;
use AhmedBhs\DoctrineDoctor\Collector\DoctrineDoctorDataCollector;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\CollectorFormatterInterface;
use Symfony\Component\HttpKernel\DataCollector\DataCollectorInterface;

/**
 * @implements CollectorFormatterInterface<DoctrineDoctorDataCollector>
 */
final readonly class DoctrineDoctorCollectorFormatter implements CollectorFormatterInterface
{
    public function __construct(private DoctrineDoctorMcpSanitizer $sanitizer)
    {
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

        return [
            'stats' => $collector->getStats(),
            'database_info' => $collector->getDatabaseInfo(),
            'profiler_overhead' => $collector->getProfilerOverhead(),
            'issues' => $this->sanitizer->sanitizeIssues(
                $collector->getIssues(),
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

        return $collector->getStats();
    }
}

