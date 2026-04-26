<?php

declare(strict_types=1);

use AhmedBhs\DoctrineDoctor\AiMate\Capability\DoctrineDoctorIssuesTool;
use AhmedBhs\DoctrineDoctor\AiMate\DoctrineDoctorMcpSanitizer;
use AhmedBhs\DoctrineDoctor\AiMate\Formatter\DoctrineDoctorCollectorFormatter;
use AhmedBhs\DoctrineDoctor\AiMate\TraceSanitizer;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\ProfilerDataProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set(TraceSanitizer::class)
        ->args([param('kernel.project_dir')]);

    $services->set(DoctrineDoctorMcpSanitizer::class)
        ->args([service(TraceSanitizer::class)]);

    $services->set(DoctrineDoctorCollectorFormatter::class)
        ->args([service(DoctrineDoctorMcpSanitizer::class)])
        ->tag('ai_mate.profiler_collector_formatter');

    $services->set(DoctrineDoctorIssuesTool::class)
        ->args([
            service(ProfilerDataProvider::class),
            service(DoctrineDoctorMcpSanitizer::class),
        ]);
};

