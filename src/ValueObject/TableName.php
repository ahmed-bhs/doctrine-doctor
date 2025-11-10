<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\ValueObject;

use Webmozart\Assert\Assert;

/**
 * Value Object representing a database table name.
 * Handles name validation and entity inference.
 */
final class TableName implements \Stringable
{
    private function __construct(
        /**
         * @readonly
         */
        private string $value,
    ) {
        Assert::stringNotEmpty($value, 'Table name cannot be empty');
        Assert::regex(
            $value,
            '/^[a-zA-Z_]\w*$/',
            'Invalid table name "%s". Must contain only alphanumeric characters and underscores',
        );
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Convert table name to entity name (PascalCase).
     * Example: user_profile -> UserProfile.
     */
    public function toEntityName(): EntityName
    {
        // Remove common prefixes
        $cleanName = preg_replace('/^(tbl_|tb_)/', '', $this->value);

        // Convert to PascalCase
        $parts      = explode('_', (string) $cleanName);
        $entityName = implode('', array_map(function ($part) {
            return ucfirst($part);
        }, $parts));

        return EntityName::fromString($entityName);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
