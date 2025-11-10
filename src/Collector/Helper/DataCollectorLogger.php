<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector\Helper;

use Psr\Log\LoggerInterface;

/**
 * Helper for conditional logging in DataCollector.
 * Extracted from DoctrineDoctorDataCollector to reduce complexity.
 */
final class DataCollectorLogger
{
    public function __construct(
        /**
         * @readonly
         */
        private LoggerInterface $logger,
        /**
         * @readonly
         */
        private ?DataCollectorConfig $dataCollectorConfig = null
    ) {
        $this->dataCollectorConfig = $dataCollectorConfig ?? new DataCollectorConfig();
    }

    /**
     * Log error if debug mode is enabled.
     */
    public function logErrorIfDebugEnabled(string $message, \Throwable $throwable): void
    {
        if ($this->dataCollectorConfig->debugMode ?? false) {
            $this->logger->error('DoctrineDoctor: ' . $message, [
                'exception' => $throwable::class,
                'message'   => $throwable->getMessage(),
                'file'      => $throwable->getFile(),
                'line'      => $throwable->getLine(),
            ]);
        }
    }

    /**
     * Log warning if debug mode is enabled.
     */
    public function logWarningIfDebugEnabled(string $message, \Throwable $throwable): void
    {
        if ($this->dataCollectorConfig->debugMode ?? false) {
            $this->logger->warning('DoctrineDoctor: ' . $message, [
                'exception' => $throwable::class,
                'message'   => $throwable->getMessage(),
                'file'      => $throwable->getFile(),
                'line'      => $throwable->getLine(),
            ]);
        }
    }

    /**
     * Log debug message if debug mode is enabled.
     */
    public function logDebugIfEnabled(string $message, \Throwable $throwable): void
    {
        if ($this->dataCollectorConfig->debugMode ?? false) {
            $this->logger->debug('DoctrineDoctor: ' . $message, [
                'exception' => $throwable::class,
                'message'   => $throwable->getMessage(),
                'file'      => $throwable->getFile(),
                'line'      => $throwable->getLine(),
            ]);
        }
    }

    /**
     * Log info message if debug mode is enabled.
     * @param array<string, mixed> $context Additional context data
     */
    public function logInfoIfEnabled(string $message, array $context = []): void
    {
        if ($this->dataCollectorConfig->debugMode ?? false) {
            $this->logger->info('DoctrineDoctor: ' . $message, $context);
        }
    }
}
