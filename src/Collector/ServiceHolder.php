<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector;

use Webmozart\Assert\Assert;

/**
 * Holds services during profiler serialization/deserialization.
 * DataCollector instances are serialized and stored, which means injected services
 * are lost. This holder uses a static cache to keep services alive during the
 * request lifecycle, allowing lateCollect() to access them.
 */
class ServiceHolder
{
    /**
     * @var array<string, ServiceHolderData>
     */
    private static array $services = [];

    /**
     * Store services for a given token.
     */
    public static function store(string $token, ServiceHolderData $serviceHolderData): void
    {
        Assert::stringNotEmpty($token, 'Token cannot be empty');
        self::$services[$token] = $serviceHolderData;
    }

    /**
     * Retrieve services for a given token.
     */
    public static function get(string $token): ?ServiceHolderData
    {
        return self::$services[$token] ?? null;
    }

    /**
     * Clear services for a given token.
     */
    public static function clear(string $token): void
    {
        unset(self::$services[$token]);
    }

    /**
     * Clear all stored services (useful for testing).
     */
    public static function clearAll(): void
    {
        self::$services = [];
    }
}
