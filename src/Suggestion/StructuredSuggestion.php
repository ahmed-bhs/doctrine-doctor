<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Suggestion;

use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionContent;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

/**
 * A suggestion with structured content (text, code blocks, links, etc.).
 * This provides a better user experience by organizing suggestion content
 * in a clear, standardized format.
 */
final readonly class StructuredSuggestion implements SuggestionInterface
{
    public function __construct(
        private string $title,
        private SuggestionContent $suggestionContent,
        private SuggestionMetadata $suggestionMetadata,
        private ?string $summary = null,
    ) {
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getContent(): SuggestionContent
    {
        return $this->suggestionContent;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /**
     * Get the code (rendered HTML for profiler).
     */
    public function getCode(): string
    {
        return $this->suggestionContent->toHtml();
    }

    /**
     * Get the description (summary or first text block).
     */
    public function getDescription(): string
    {
        if (null !== $this->summary && '' !== $this->summary) {
            return $this->summary;
        }

        // Fallback: get first text block
        foreach ($this->suggestionContent->getBlocks() as $suggestionContentBlock) {
            if ('text' === $suggestionContentBlock->getType()) {
                $content = $suggestionContentBlock->getContent();
                assert(is_string($content), 'Text block content must be string');
                return $content;
            }
        }

        return $this->title;
    }

    public function getMetadata(): SuggestionMetadata
    {
        return $this->suggestionMetadata;
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'class'   => self::class,
            'title'   => $this->title,
            'summary' => $this->summary,
            'content' => $this->suggestionContent->toArray(),
            // For backward compatibility
            'code'        => $this->getCode(),
            'description' => $this->getDescription(),
        ];
    }

    public static function fromArray(array $data): self
    {
        $metadata = isset($data['metadata'])
            ? new SuggestionMetadata(
                type: SuggestionType::from($data['metadata']['type'] ?? 'refactoring'),
                severity: Severity::from($data['metadata']['severity'] ?? 'info'),
                title: $data['metadata']['title'] ?? $data['title'] ?? 'Suggestion',
                tags: $data['metadata']['tags'] ?? [],
            )
            : new SuggestionMetadata(
                type: SuggestionType::REFACTORING,
                severity: Severity::INFO,
                title: $data['title'] ?? 'Suggestion',
                tags: [],
            );

        return new self(
            title: $data['title'] ?? 'Suggestion',
            suggestionContent: SuggestionContent::fromArray($data['content'] ?? ['blocks' => []]),
            summary: $data['summary'] ?? null,
            suggestionMetadata: $metadata,
        );
    }
}
