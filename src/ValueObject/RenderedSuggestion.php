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

/**
 * Value Object representing a rendered suggestion with formatted output.
 * Immutable.
 */
final class RenderedSuggestion
{
    public function __construct(
        /**
         * @readonly
         */
        public string $code,
        /**
         * @readonly
         */
        public string $description,
        /**
         * @readonly
         */
        public SuggestionMetadata $metadata,
    ) {
        if ('' === $description || '0' === $description) {
            throw new InvalidArgumentException('Description cannot be empty');
        }
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'code'        => $this->code,
            'description' => $this->description,
            'metadata'    => $this->metadata->toArray(),
        ];
    }
}
