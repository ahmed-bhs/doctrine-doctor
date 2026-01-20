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
 * Suggestion types for Doctrine Doctor recommendations.
 * PHP 8.1+ native enum for type safety and better IDE support.
 */
enum SuggestionType: string
{
    case PERFORMANCE = 'performance';
    case SECURITY = 'security';
    case CONFIGURATION = 'configuration';
    case INTEGRITY = 'integrity';
    case BEST_PRACTICE = 'best_practice';
    case REFACTORING = 'refactoring';

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

    /**
     * Named constructor for performance type.
     */
    public static function performance(): self
    {
        return self::PERFORMANCE;
    }

    /**
     * Named constructor for security type.
     */
    public static function security(): self
    {
        return self::SECURITY;
    }

    /**
     * Named constructor for configuration type.
     */
    public static function configuration(): self
    {
        return self::CONFIGURATION;
    }

    /**
     * Named constructor for integrity type.
     */
    public static function integrity(): self
    {
        return self::INTEGRITY;
    }

    /**
     * Named constructor for best practice type.
     */
    public static function bestPractice(): self
    {
        return self::BEST_PRACTICE;
    }

    /**
     * Named constructor for refactoring type.
     */
    public static function refactoring(): self
    {
        return self::REFACTORING;
    }

    /**
     * Check equality with another SuggestionType.
     */
    public function equals(self $other): bool
    {
        return $this === $other;
    }

    /**
     * Check if a type is valid.
     */
    public static function isValid(string $type): bool
    {
        return null !== self::tryFrom($type);
    }

    /**
     * Get all suggestion types.
     * @return self[]
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Get emoji representation for suggestion type.
     *  Performance,  Security,  Configuration,  Integrity,  Best Practice,  Refactoring
     */
    public function getEmoji(): string
    {
        return match ($this) {
            self::PERFORMANCE => '🔴',
            self::SECURITY => '🟢',
            self::CONFIGURATION => '🔵',
            self::INTEGRITY => '🟣',
            self::BEST_PRACTICE => '🟠',
            self::REFACTORING => '🟡',
        };
    }
}
