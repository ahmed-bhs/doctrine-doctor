<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\AiMate\Capability;

use AhmedBhs\DoctrineDoctor\AiMate\DoctrineDoctorMcpSanitizer;
use AhmedBhs\DoctrineDoctor\Collector\DoctrineDoctorDataCollector;
use Mcp\Capability\Attribute\McpTool;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\ProfilerDataProvider;
use Symfony\Component\HttpKernel\DataCollector\DataCollectorInterface;

use function implode;
use function sprintf;

final readonly class DoctrineDoctorIssuesTool
{
    public function __construct(
        private ProfilerDataProvider $dataProvider,
        private DoctrineDoctorMcpSanitizer $sanitizer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        'doctrine-doctor-issues',
        'List Doctrine Doctor issues from a Symfony Profiler request. '
        . 'Filter by category (performance, security, integrity, configuration) '
        . 'or severity (critical, warning, info). If no token is provided, uses the latest profiler request.',
    )]
    public function getIssues(
        ?string $token = null,
        ?string $category = null,
        ?string $severity = null,
        int $limit = 20,
        bool $includeQueries = false,
    ): array {
        $collector = $this->getCollector($token);

        if (!$collector instanceof DoctrineDoctorDataCollector) {
            return $collector;
        }

        return [
            'stats' => $collector->getStats(),
            'issues' => $this->sanitizer->sanitizeIssues(
                $collector->getIssues(),
                category: $category,
                severity: $severity,
                limit: $limit,
                includeQueries: $includeQueries,
            ),
        ];
    }

    /**
     * @return DoctrineDoctorDataCollector|array<string, mixed>
     */
    private function getCollector(?string $token): DoctrineDoctorDataCollector|array
    {
        if ($token === null) {
            $latest = $this->dataProvider->getLatestProfile();

            if ($latest === null) {
                return ['error' => 'No profiler profiles found'];
            }

            $token = $latest->getToken();
        }

        $profileData = $this->dataProvider->findProfile($token);

        if ($profileData === null) {
            return ['error' => sprintf('Profile not found for token: %s', $token)];
        }

        $collectors = $profileData->getProfile()->getCollectors();

        foreach ($collectors as $collector) {
            if (
                $collector->getName() === 'doctrine_doctor'
                && $collector instanceof DoctrineDoctorDataCollector
            ) {
                return $collector;
            }
        }

        return [
            'error' => sprintf(
                'doctrine_doctor collector not found in profile %s. Available collectors: %s',
                $token,
                implode(', ', array_map(
                    static fn (DataCollectorInterface $collector): string => $collector->getName(),
                    $collectors,
                )),
            ),
        ];
    }
}
