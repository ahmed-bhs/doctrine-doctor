<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Doctrine\Set\DoctrineSetList;
use Rector\Symfony\Set\SymfonySetList;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Symfony\CodeQuality\Rector\Class_\EventSubscriberToAttributeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/tests/Fixtures',
        // Subscribers are registered with an explicit kernel.event_subscriber tag
        EventSubscriberToAttributeRector::class,
    ])
    // GitHub runners are slower than the default 120s per parallel job
    ->withParallel(timeoutSeconds: 300)
    ->withSets([
        DoctrineSetList::COMPOSER_BASED,
        LevelSetList::UP_TO_PHP_84,
        SymfonySetList::COMPOSER_BASED,
    ]);
