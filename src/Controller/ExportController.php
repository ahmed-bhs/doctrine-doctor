<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Controller;

use AhmedBhs\DoctrineDoctor\Collector\DoctrineDoctorDataCollector;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ExportController
{
    public function __construct(private Profiler $profiler) {}

    #[Route('/_profiler/{token}/doctrine_doctor_export', name: 'doctrine_doctor_export', methods: ['GET'])]
    public function export(string $token): JsonResponse
    {
        $profile = $this->profiler->loadProfile($token);
        assert($profile instanceof Profile);

        $collector = $profile->getCollector('doctrine_doctor');
        assert($collector instanceof DoctrineDoctorDataCollector);

        $response = new JsonResponse(self::createExport($collector));
        $response->setEncodingOptions($response->getEncodingOptions() | \JSON_PRETTY_PRINT);
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="doctrine-doctor-%s.json"', $token));
        
        return $response;
    }

    /**
     * @return array{
     *  issues: array<int, array<string, mixed>>,
     *  stats: array<string, mixed>,
     *  queries: array<int, array{
     *     sql: string,
     *     count: int,
     *     totalTimeMs: float,
     *     avgTimeMs: float,
     *     maxTimeMs: float,
     *     minTimeMs: float,
     *  }>
     * }
     */
    private static function createExport(DoctrineDoctorDataCollector $collector): array
    {
        return [
            'issues' => self::extractIssues($collector),
            'stats' => $collector->getStats(),
            'grouped_queries_by_time' => self::extractQueries($collector),
        ];
    }

    /**
     * Extract issue information from the collector. Call toArray() on each issue object.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function extractIssues(DoctrineDoctorDataCollector $collector): array
    {
        return array_map(
            static fn (IssueInterface $issue) => $issue->toArray(),
            $collector->getIssues(),
        );
    }

    /**
     * Extract query information from the collector and avoid to extract non serializable information.
     *
     * @return array<int, array{
     *     sql: string,
     *     count: int,
     *     totalTimeMs: float,
     *     avgTimeMs: float,
     *     maxTimeMs: float,
     *     minTimeMs: float,
     * }>
     */
    private static function extractQueries(DoctrineDoctorDataCollector $collector): array
    {
        return array_map(
            static function (array $query): array {
                return [
                    'sql' => $query['sql'],
                    'count' => $query['count'],
                    'totalTimeMs' => $query['totalTimeMs'],
                    'avgTimeMs' => $query['avgTimeMs'],
                    'maxTimeMs' => $query['maxTimeMs'],
                    'minTimeMs' => $query['minTimeMs'],
                    // Note: we ignore first query here because it can't be serialized without errors.
                ];
            },
            $collector->getGroupedQueriesByTime(),
        );
    }
}
