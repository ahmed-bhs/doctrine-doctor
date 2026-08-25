<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Doctrine\Set\DoctrineSetList;
use Rector\Symfony\Set\SymfonySetList;
use Rector\Set\ValueObject\LevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/tests/Fixtures',
    ])
    ->withSets([
        DoctrineSetList::COMPOSER_BASED,
        LevelSetList::UP_TO_PHP_84,
        SymfonySetList::COMPOSER_BASED,
    ]);
