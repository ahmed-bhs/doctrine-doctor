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
use AhmedBhs\DoctrineDoctor\Service\ExportDataFormatter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ExportController
{
    public function __construct(
        private Profiler $profiler,
        private ExportDataFormatter $formatter,
    ) {}

    #[Route('/doctrine-doctor/{token}/export', name: 'doctrine_doctor_export', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9]++'])]
    public function export(string $token): JsonResponse
    {
        $profile = $this->profiler->loadProfile($token);
        assert($profile instanceof Profile);

        $collector = $profile->getCollector('doctrine_doctor');
        assert($collector instanceof DoctrineDoctorDataCollector);

        $response = new JsonResponse($this->formatter->format($collector));
        $response->setEncodingOptions($response->getEncodingOptions() | \JSON_PRETTY_PRINT);
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="doctrine-doctor-%s.json"', $token));

        return $response;
    }
}
