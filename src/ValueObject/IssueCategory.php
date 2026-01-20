<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\ValueObject;

use InvalidArgumentException;

/**
 * Issue categories for organizing Doctrine Doctor issues in the profiler.
 * PHP 8.1+ native enum for type safety and better IDE support.
 * Each issue must belong to one of these categories to be properly displayed
 * in the Symfony Web Profiler panel.
 */
enum IssueCategory: string
{
    /**
     * Performance-related issues (slow queries, N+1, missing indexes, etc.).
     */
    case PERFORMANCE = 'performance';

    /**
     * Security vulnerabilities (SQL injection, DQL injection, etc.).
     */
    case SECURITY = 'security';

    /**
     * Data integrity issues (bad practices, type mismatches, cascade config, etc.).
     */
    case INTEGRITY = 'integrity';

    /**
     * Database configuration issues (charset, collation, strict mode, etc.).
     */
    case CONFIGURATION = 'configuration';

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
     * All valid categories.
     * @return self[]
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Check if a category is valid.
     */
    public static function isValid(string $category): bool
    {
        return null !== self::tryFrom($category);
    }

    /**
     * Validate a category and throw exception if invalid.
     * @throws \InvalidArgumentException
     */
    public static function validate(string $category): void
    {
        if (!self::isValid($category)) {
            $validValues = array_map(fn (self $case) => $case->value, self::cases());
            throw new InvalidArgumentException(
                sprintf('Invalid issue category "%s". Must be one of: %s', $category, implode(', ', $validValues)),
            );
        }
    }

    /**
     * Named constructor for performance category.
     */
    public static function performance(): self
    {
        return self::PERFORMANCE;
    }

    /**
     * Named constructor for security category.
     */
    public static function security(): self
    {
        return self::SECURITY;
    }

    /**
     * Named constructor for integrity category.
     */
    public static function integrity(): self
    {
        return self::INTEGRITY;
    }

    /**
     * Named constructor for configuration category.
     */
    public static function configuration(): self
    {
        return self::CONFIGURATION;
    }
}
