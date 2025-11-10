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
 * Value Object representing an entity name.
 */
final class EntityName implements \Stringable
{
    private function __construct(
        /**
         * @readonly
         */
        private string $value,
    ) {
        Assert::stringNotEmpty($value, 'Entity name cannot be empty');
        Assert::regex(
            $value,
            '/^[A-Z][a-zA-Z0-9]*$/',
            'Invalid entity name "%s". Must be in PascalCase',
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

    public function getShortName(): string
    {
        $parts = explode('\\', $this->value);

        return end($parts);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
