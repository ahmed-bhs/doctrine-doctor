<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\DTO;

use Webmozart\Assert\Assert;

/**
 * Data Transfer Object representing a code suggestion.
 * Immutable and type-safe.
 */
final class SuggestionData
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
        public ?string $category = null,
    ) {
        Assert::stringNotEmpty($code, 'Suggestion code cannot be empty');
        Assert::stringNotEmpty($description, 'Suggestion description cannot be empty');
    }

    /**
     * Create from array (legacy compatibility).
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            code: $data['code'] ?? '',
            description: $data['description'] ?? 'No description',
            category: $data['category'] ?? null,
        );
    }

    /**
     * Convert to array (for serialization).
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code'        => $this->code,
            'description' => $this->description,
            'category'    => $this->category,
        ];
    }
}
