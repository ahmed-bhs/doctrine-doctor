<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Helper;

use Webmozart\Assert\Assert;

/**
 * Helper class to access mapping properties in a version-agnostic way.
 * Supports Doctrine ORM 2.x (arrays), 3.x and 4.x (objects).
 */
class MappingHelper
{
    /**
     * Get a mapping property value in a version-agnostic way.
     * @param array<string, mixed>|object $mapping  Field or association mapping
     * @param string                      $property Property name to access
     * @return mixed The property value or null if not found
     */
    public static function getProperty(array|object $mapping, string $property): mixed
    {
        if (is_array($mapping)) {
            // Doctrine ORM 2.x: array
            return $mapping[$property] ?? null;
        }

        // Doctrine ORM 3.x/4.x: Mapping object with public properties
        // Use property_exists for maximum compatibility
        if (property_exists($mapping, $property)) {
            $vars = get_object_vars($mapping);
            return $vars[$property] ?? null;
        }

        return null;
    }

    /**
     * Check if a property exists in the mapping.
     * @param array<string, mixed>|object $mapping  Field or association mapping
     * @param string                      $property Property name to check
     */
    public static function hasProperty(array|object $mapping, string $property): bool
    {
        if (is_array($mapping)) {
            return isset($mapping[$property]);
        }

        if (!property_exists($mapping, $property)) {
            return false;
        }

        $vars = get_object_vars($mapping);
        return isset($vars[$property]);
    }

    /**
     * Get a string property from mapping (targetEntity, mappedBy, inversedBy, fieldName, type, etc.).
     * @param array<string, mixed>|object $mapping
     */
    public static function getString(array|object $mapping, string $property): ?string
    {
        $value = self::getProperty($mapping, $property);

        if (null === $value) {
            return null;
        }

        Assert::string($value, sprintf('Expected string for property "%s", got %%s', $property));

        return $value;
    }

    /**
     * Get an array property from mapping (cascade, joinColumns, fields, etc.).
     * @param array<string, mixed>|object $mapping
     * @return array<mixed>|null
     */
    public static function getArray(array|object $mapping, string $property): ?array
    {
        $value = self::getProperty($mapping, $property);

        if (null === $value) {
            return null;
        }

        Assert::isArray($value, sprintf('Expected array for property "%s", got %%s', $property));

        return $value;
    }

    /**
     * Get a boolean property from mapping (orphanRemoval, nullable, unique, etc.).
     * @param array<string, mixed>|object $mapping
     */
    public static function getBool(array|object $mapping, string $property): ?bool
    {
        $value = self::getProperty($mapping, $property);

        if (null === $value) {
            return null;
        }

        Assert::boolean($value, sprintf('Expected bool for property "%s", got %%s', $property));

        return $value;
    }

    /**
     * Get an integer property from mapping (type constant, length, precision, scale, etc.).
     * @param array<string, mixed>|object $mapping
     */
    public static function getInt(array|object $mapping, string $property): ?int
    {
        $value = self::getProperty($mapping, $property);

        if (null === $value) {
            return null;
        }

        Assert::integer($value, sprintf('Expected int for property "%s", got %%s', $property));

        return $value;
    }
}
