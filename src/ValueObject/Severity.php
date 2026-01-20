<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\ValueObject;

/**
 * Issue severity levels.
 * PHP 8.1+ native enum for type safety and better IDE support.
 *
 * 3-Level Classification:
 * - CRITICAL: Critical priority, severe performance or security issue
 * - WARNING: Medium priority, should be addressed
 * - INFO: Informational, low priority
 */
enum Severity: string
{
    case CRITICAL = 'critical';
    case WARNING = 'warning';
    case INFO = 'info';

    /**
     * Create from string value (for backward compatibility).
     */
    public static function fromString(string $value): self
    {
        return self::from($value);
    }

    /**
     * Get the string value (for backward compatibility).
     */
    public function getValue(): string
    {
        return $this->value;
    }

    public function isCritical(): bool
    {
        return self::CRITICAL === $this;
    }

    public function isWarning(): bool
    {
        return self::WARNING === $this;
    }

    public function isInfo(): bool
    {
        return self::INFO === $this;
    }

    /**
     * Named constructor for critical severity.
     */
    public static function critical(): self
    {
        return self::CRITICAL;
    }

    /**
     * Named constructor for warning severity.
     */
    public static function warning(): self
    {
        return self::WARNING;
    }

    /**
     * Named constructor for info severity.
     */
    public static function info(): self
    {
        return self::INFO;
    }

    /**
     * Get numeric priority (higher = more severe).
     * Useful for sorting and comparisons.
     */
    public function getPriority(): int
    {
        return match ($this) {
            self::CRITICAL => 3,
            self::WARNING => 2,
            self::INFO => 1,
        };
    }

    /**
     * Compare severity levels.
     * Returns: negative if $this < $other, 0 if equal, positive if $this > $other
     */
    public function compareTo(self $other): int
    {
        return $this->getPriority() <=> $other->getPriority();
    }

    /**
     * Check if this severity is higher than another.
     */
    public function isHigherThan(self $other): bool
    {
        return $this->getPriority() > $other->getPriority();
    }

    /**
     * Check if this severity is lower than another.
     */
    public function isLowerThan(self $other): bool
    {
        return $this->getPriority() < $other->getPriority();
    }

    /**
     * Get emoji representation for severity.
     */
    public function getEmoji(): string
    {
        return match ($this) {
            self::CRITICAL => '🔴',
            self::WARNING => '🟠',
            self::INFO => '🔵',
        };
    }

    /**
     * Get color name for severity (for UI/styling).
     */
    public function getColor(): string
    {
        return match ($this) {
            self::CRITICAL => 'red',
            self::WARNING => 'yellow',
            self::INFO => 'lightblue',
        };
    }
}
