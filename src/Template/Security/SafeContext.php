<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Template\Security;

use ArrayAccess;
use BadMethodCallException;
use InvalidArgumentException;
use Webmozart\Assert\Assert;

/**
 * Safe context wrapper with automatic HTML escaping.
 * Security Features:
 * - Auto-escapes all string values by default
 * - Provides raw() method for intentional unescaped output
 * - Prevents variable overwriting with immutability
 * - Type-safe access to context variables
 * Usage in templates:
 * ```php
 * // Auto-escaped
 * echo $context->username; // Safe
 * // Intentional raw output (for pre-formatted HTML)
 * echo $context->raw('formatted_sql'); // Unsafe - use with caution
 * // Array access also works
 * echo $context['username']; // Safe
 * ```
 * @implements ArrayAccess<string, mixed>
 */
final class SafeContext implements ArrayAccess
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        /**
         * @readonly
         */
        private array $data,
    ) {
    }

    /**
     * Get escaped value (safe for HTML output).
     * @param string $key Variable name
     * @return mixed Escaped value if string, original value otherwise
     */
    public function __get(string $key): mixed
    {
        if (!array_key_exists($key, $this->data)) {
            throw new InvalidArgumentException(sprintf('Undefined context variable: %s', $key));
        }

        return $this->escape($this->data[$key]);
    }

    /**
     * Get raw (unescaped) value.
     * WARNING: Only use this for pre-sanitized content or when you need
     * to output HTML intentionally (e.g., formatted SQL, code blocks).
     * @param string $key Variable name
     * @return mixed Raw value without escaping
     */
    public function raw(string $key): mixed
    {
        if (!array_key_exists($key, $this->data)) {
            throw new InvalidArgumentException(sprintf('Undefined context variable: %s', $key));
        }

        return $this->data[$key];
    }

    /**
     * Check if variable exists in context.
     * @param string $key Variable name
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Get all keys in context.
     * @return array<string>
     */
    public function keys(): array
    {
        return array_keys($this->data);
    }

    /**
     * ArrayAccess: Check if offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        Assert::string($offset, 'Offset must be string, got %s');

        return array_key_exists($offset, $this->data);
    }

    /**
     * ArrayAccess: Get escaped value.
     * @return mixed Escaped value
     */
    public function offsetGet(mixed $offset): mixed
    {
        Assert::string($offset, 'Offset must be string, got %s');

        return $this->__get($offset);
    }

    /**
     * ArrayAccess: Set value (disabled - immutable).
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new BadMethodCallException('SafeContext is immutable. Cannot modify values.');
    }

    /**
     * ArrayAccess: Unset value (disabled - immutable).
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new BadMethodCallException('SafeContext is immutable. Cannot unset values.');
    }

    /**
     * Escape value for safe HTML output.
     * @param mixed $value Value to escape
     * @return mixed Escaped value if string, original value otherwise
     */
    private function escape(mixed $value): mixed
    {
        // Recursively escape arrays
        if (is_array($value)) {
            return array_map(fn ($item) => $this->escape($item), $value);
        }

        // Escape strings
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Return other types as-is (int, float, bool, objects, null)
        return $value;
    }
}
