<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Collector;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

/**
 * Like Symfony's FormDataCollector, whose __serialize() assumes live data:
 * a collector loaded from the profiler storage cannot be serialized again.
 */
final class ReserializationGuardCollector extends DataCollector
{
    private bool $loadedFromStorage = false;

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
    }

    public function getName(): string
    {
        return 'reserialization_guard';
    }

    public function __serialize(): array
    {
        if ($this->loadedFromStorage) {
            throw new \LogicException('A collector loaded from the profiler storage must not be serialized again');
        }

        return ['data' => $this->data];
    }

    public function __unserialize(array $data): void
    {
        $this->data = $data['data'];
        $this->loadedFromStorage = true;
    }
}
