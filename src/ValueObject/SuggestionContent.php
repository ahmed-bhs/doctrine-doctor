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
 * Represents structured content for a suggestion.
 * Supports different content types: code blocks, text, options, links, warnings, etc.
 */
final class SuggestionContent
{
    /**
     * @param SuggestionContentBlock[] $blocks
     */
    public function __construct(
        /** @var array<mixed>
         * @readonly */
        private array $blocks,
    ) {
        Assert::allIsInstanceOf($blocks, SuggestionContentBlock::class, 'All blocks must be instances of SuggestionContentBlock');
    }

    /**
     * @return SuggestionContentBlock[]
     */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'blocks' => array_map(fn (SuggestionContentBlock $suggestionContentBlock): array => $suggestionContentBlock->toArray(), $this->blocks),
        ];
    }

    public static function fromArray(array $data): self
    {
        $blocks = [];

        foreach ($data['blocks'] ?? [] as $blockData) {
            $blocks[] = SuggestionContentBlock::fromArray($blockData);
        }

        return new self($blocks);
    }

    /**
     * Render as HTML for the profiler.
     */
    public function toHtml(): string
    {
        $html = '';

        foreach ($this->blocks as $block) {
            $html .= $block->toHtml();
        }

        return $html;
    }

    /**
     * Render as plain text (for CLI or logs).
     */
    public function toText(): string
    {
        $text = '';

        foreach ($this->blocks as $block) {
            $text .= $block->toText() . "

";
        }

        return trim($text);
    }
}
