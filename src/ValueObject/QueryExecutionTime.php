<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\ValueObject;

use InvalidArgumentException;
use Webmozart\Assert\Assert;

/**
 * Value Object representing query execution time.
 * Handles time conversions and comparisons.
 */
final class QueryExecutionTime implements \Stringable
{
    private function __construct(
        /**
         * @readonly
         */
        private float $milliseconds,
    ) {
        Assert::greaterThanEq($milliseconds, 0, 'Execution time cannot be negative, got %s');

        if (!is_finite($milliseconds)) {
            throw new InvalidArgumentException(sprintf('Execution time must be a finite number, got %s', $milliseconds));
        }
    }

    public function __toString(): string
    {
        return $this->format();
    }

    public static function fromMilliseconds(float $milliseconds): self
    {
        return new self($milliseconds);
    }

    public static function fromSeconds(float $seconds): self
    {
        return new self($seconds * 1000);
    }

    public function inMilliseconds(): float
    {
        return $this->milliseconds;
    }

    public function inSeconds(): float
    {
        return $this->milliseconds / 1000;
    }

    public function isSlow(float $thresholdMs = 100.0): bool
    {
        Assert::greaterThan($thresholdMs, 0, 'Threshold must be positive, got %s');

        return $this->milliseconds > $thresholdMs;
    }

    public function isFast(float $thresholdMs = 10.0): bool
    {
        Assert::greaterThan($thresholdMs, 0, 'Threshold must be positive, got %s');

        return $this->milliseconds < $thresholdMs;
    }

    public function add(self $other): self
    {
        return new self($this->milliseconds + $other->milliseconds);
    }

    public function isSlowerThan(self $other): bool
    {
        return $this->milliseconds > $other->milliseconds;
    }

    public function format(): string
    {
        if ($this->milliseconds >= 1000) {
            return sprintf('%.2fs', $this->inSeconds());
        }

        return sprintf('%.2fms', $this->milliseconds);
    }
}
