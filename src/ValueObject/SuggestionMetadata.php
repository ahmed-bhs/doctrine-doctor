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
 * Value Object containing metadata about a suggestion.
 * Immutable and type-safe.
 */
final class SuggestionMetadata
{
    /**
     * @param array<string> $tags
     */
    public function __construct(
        /**
         * @readonly
         */
        public SuggestionType $type,
        /**
         * @readonly
         */
        public Severity $severity,
        /**
         * @readonly
         */
        public string $title,
        /** @var array<mixed>
         * @readonly */
        public array $tags = [],
    ) {
        Assert::stringNotEmpty($title, 'Title cannot be empty');
    }

    public function withSeverity(Severity $severity): self
    {
        return new self(
            type: $this->type,
            severity: $severity,
            title: $this->title,
            tags: $this->tags,
        );
    }

    public function withTags(array $tags): self
    {
        return new self(
            type: $this->type,
            severity: $this->severity,
            title: $this->title,
            tags: $tags,
        );
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'type'     => $this->type->getValue(),
            'severity' => $this->severity->getValue(),
            'title'    => $this->title,
            'tags'     => $this->tags,
        ];
    }
}
