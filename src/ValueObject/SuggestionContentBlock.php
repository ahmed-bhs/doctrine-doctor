<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\ValueObject;

use AhmedBhs\DoctrineDoctor\ValueObject\Helper\MarkdownFormatter;
use Webmozart\Assert\Assert;

/**
 * Represents a single block of content in a suggestion.
 * Supported types:
 * - text: Plain text or description
 * - code: Code example (good or bad)
 * - heading: Section heading
 * - list: Bulleted or numbered list
 * - warning: Warning message
 * - info: Informational message
 * - link: External link (documentation, etc.)
 * - comparison: Side-by-side comparison of bad vs good code
 */
final class SuggestionContentBlock
{
    public const TYPE_TEXT = 'text';

    public const TYPE_CODE = 'code';

    public const TYPE_HEADING = 'heading';

    public const TYPE_LIST = 'list';

    public const TYPE_WARNING = 'warning';

    public const TYPE_INFO = 'info';

    public const TYPE_LINK = 'link';

    public const TYPE_COMPARISON = 'comparison';

    public function __construct(
        /**
         * @readonly
         */
        private string $type,
        /**
         * @readonly
         */
        private string|array $content,
        /**
         * @readonly
         */
        private ?array $metadata = null,
    ) {
        $allowedTypes = [
            self::TYPE_TEXT,
            self::TYPE_CODE,
            self::TYPE_HEADING,
            self::TYPE_LIST,
            self::TYPE_WARNING,
            self::TYPE_INFO,
            self::TYPE_LINK,
            self::TYPE_COMPARISON,
        ];

        Assert::inArray($type, $allowedTypes, 'Invalid block type: %s');
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getContent(): string|array
    {
        return $this->content;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'type'     => $this->type,
            'content'  => $this->content,
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? self::TYPE_TEXT,
            content: $data['content'] ?? '',
            metadata: $data['metadata'] ?? null,
        );
    }

    /**
     * Create a text block.
     */
    public static function text(string $content): self
    {
        return new self(self::TYPE_TEXT, $content);
    }

    /**
     * Create a code block.
     * @param string      $code     The code content
     * @param string      $language Language for syntax highlighting (php, sql, yaml, etc.)
     * @param string|null $label    Label like "Good" or "Bad"
     */
    public static function code(string $code, string $language = 'php', ?string $label = null): self
    {
        return new self(self::TYPE_CODE, $code, [
            'language' => $language,
            'label'    => $label,
        ]);
    }

    /**
     * Create a heading block.
     */
    public static function heading(string $text, int $level = 3): self
    {
        return new self(self::TYPE_HEADING, $text, ['level' => $level]);
    }

    /**
     * Create an ordered (numbered) list block.
     * @param array<mixed> $items List items
     */
    public static function orderedList(array $items): self
    {
        return new self(self::TYPE_LIST, $items, ['ordered' => true]);
    }

    /**
     * Create an unordered (bulleted) list block.
     * @param array<mixed> $items List items
     */
    public static function unorderedList(array $items): self
    {
        return new self(self::TYPE_LIST, $items, ['ordered' => false]);
    }

    /**
     * Create a warning block.
     */
    public static function warning(string $message): self
    {
        return new self(self::TYPE_WARNING, $message);
    }

    /**
     * Create an info block.
     */
    public static function info(string $message): self
    {
        return new self(self::TYPE_INFO, $message);
    }

    /**
     * Create a link block.
     * @param string      $url  The URL
     * @param string|null $text Link text (defaults to URL if null)
     */
    public static function link(string $url, ?string $text = null): self
    {
        return new self(self::TYPE_LINK, $url, ['text' => $text ?? $url]);
    }

    /**
     * Create a comparison block (bad vs good code).
     * @param string $badCode  The problematic code
     * @param string $goodCode The recommended code
     * @param string $language Language for syntax highlighting
     */
    public static function comparison(string $badCode, string $goodCode, string $language = 'php'): self
    {
        return new self(self::TYPE_COMPARISON, [
            'bad'  => $badCode,
            'good' => $goodCode,
        ], ['language' => $language]);
    }

    /**
     * Render as HTML for the profiler.
     */
    public function toHtml(): string
    {
        return match ($this->type) {
            self::TYPE_TEXT       => $this->renderTextHtml(),
            self::TYPE_CODE       => $this->renderCodeHtml(),
            self::TYPE_HEADING    => $this->renderHeadingHtml(),
            self::TYPE_LIST       => $this->renderListHtml(),
            self::TYPE_WARNING    => $this->renderWarningHtml(),
            self::TYPE_INFO       => $this->renderInfoHtml(),
            self::TYPE_LINK       => $this->renderLinkHtml(),
            self::TYPE_COMPARISON => $this->renderComparisonHtml(),
            default               => '',
        };
    }

    /**
     * Render as plain text.
     */
    public function toText(): string
    {
        return match ($this->type) {
            self::TYPE_TEXT       => $this->getStringContent(),
            self::TYPE_CODE       => $this->renderCodeText(),
            self::TYPE_HEADING    => strtoupper($this->getStringContent()),
            self::TYPE_LIST       => $this->renderListText(),
            self::TYPE_WARNING    => ' WARNING: ' . $this->getStringContent(),
            self::TYPE_INFO       => 'ℹ️  INFO: ' . $this->getStringContent(),
            self::TYPE_LINK       => sprintf('%s: %s', $this->metadata['text'] ?? '', $this->getStringContent()),
            self::TYPE_COMPARISON => $this->renderComparisonText(),
            default               => '',
        };
    }

    /**
     * Get content as string (with assertion for PHPStan).
     */
    private function getStringContent(): string
    {
        Assert::string($this->content, 'Content must be string for this block type');
        return $this->content;
    }

    /**
     * Get content as array (with assertion for PHPStan).
     * @return array<int|string, mixed>
     */
    private function getArrayContent(): array
    {
        Assert::isArray($this->content, 'Content must be array for this block type');
        return $this->content;
    }

    private function renderTextHtml(): string
    {
        // Convert basic Markdown: **bold**, `code`, bullet lists
        $text = $this->getStringContent();

        // Bold text: **text** -> <strong>text</strong>
        $text = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);

        // Inline code: `code` -> <code>code</code>
        $text = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

        // Convert newlines to <br> but keep double newlines as paragraph breaks
        $paragraphs = explode("

", $text);
        $html       = '';

        assert(is_iterable($paragraphs), '$paragraphs must be iterable');

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ('' === $paragraph) {
                continue;
            }

            if ('0' === $paragraph) {
                continue;
            }

            // Check if it's a list
            if (1 === preg_match('/^[-•]\s/', $paragraph)) {
                $items = explode("
", $paragraph);
                $html .= '<ul>';

                assert(is_iterable($items), '$items must be iterable');

                foreach ($items as $item) {
                    $item = preg_replace('/^[-•]\s+/', '', trim($item));

                    if ('' !== $item) {
                        $html .= '<li>' . $item . '</li>';
                    }
                }

                $html .= '</ul>';
            } else {
                // Regular paragraph with line breaks
                $paragraph = nl2br($paragraph);
                $html .= '<p>' . $paragraph . '</p>';
            }
        }

        return $html;
    }

    private function renderCodeHtml(): string
    {
        $language = $this->metadata['language'] ?? 'php';
        $label    = $this->metadata['label'] ?? null;
        $code     = htmlspecialchars($this->getStringContent());

        $labelHtml = $label ? '<div class="code-label ' . ('Bad' === $label ? 'label-bad' : 'label-good') . '">' . $label . '</div>' : '';

        return <<<HTML
            <div class="code-block">
                {$labelHtml}
                <pre><code class="language-{$language}">{$code}</code></pre>
            </div>
            HTML;
    }

    private function renderHeadingHtml(): string
    {
        $level = $this->metadata['level'] ?? 3;

        return sprintf('<h%s>', $level) . htmlspecialchars($this->getStringContent()) . sprintf('</h%s>', $level);
    }

    private function renderListHtml(): string
    {
        $ordered = $this->metadata['ordered'] ?? false;
        $tag     = $ordered ? 'ol' : 'ul';
        $content = $this->getArrayContent();

        // Support markdown in list items
        $items = array_map(function ($item): string {
            $itemStr = is_string($item) ? $item : (string) $item;
            // Bold text
            $itemStr = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $itemStr);
            // Inline code
            $itemStr = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $itemStr);

            return '<li>' . $itemStr . '</li>';
        }, $content);

        return sprintf('<%s>', $tag) . implode('', $items) . sprintf('</%s>', $tag);
    }

    private function renderWarningHtml(): string
    {
        $content = MarkdownFormatter::format($this->getStringContent());

        return '<div class="alert alert-warning">' . $content . '</div>';
    }

    private function renderInfoHtml(): string
    {
        $content = MarkdownFormatter::format($this->getStringContent());

        return '<div class="alert alert-info">' . $content . '</div>';
    }

    private function renderLinkHtml(): string
    {
        $text = htmlspecialchars((string) ($this->metadata['text'] ?? ''));
        $url  = htmlspecialchars($this->getStringContent());

        return '<div class="suggestion-link"><a href="' . $url . '" target="_blank" rel="noopener">' . $text . '</a></div>';
    }

    private function renderComparisonHtml(): string
    {
        $language = $this->metadata['language'] ?? 'php';
        $content  = $this->getArrayContent();
        $badCode  = htmlspecialchars((string) ($content['bad'] ?? ''));
        $goodCode = htmlspecialchars((string) ($content['good'] ?? ''));

        return <<<HTML
            <div class="code-comparison">
                <div class="comparison-side comparison-bad">
                    <div class="code-label label-bad">📢 Bad</div>
                    <pre><code class="language-{$language}">{$badCode}</code></pre>
                </div>
                <div class="comparison-side comparison-good">
                    <div class="code-label label-good"> Good</div>
                    <pre><code class="language-{$language}">{$goodCode}</code></pre>
                </div>
            </div>
            HTML;
    }

    private function renderCodeText(): string
    {
        $label = $this->metadata['label'] ?? '';

        return ($label ? "[{$label}]
" : '') . $this->getStringContent();
    }

    private function renderListText(): string
    {
        $ordered = $this->metadata['ordered'] ?? false;
        $items   = [];
        $content = $this->getArrayContent();

        assert(is_iterable($content), '$content must be iterable');

        foreach ($content as $i => $item) {
            $itemStr = is_string($item) ? $item : (string) $item;
            $prefix  = $ordered ? ((int) $i + 1) . '. ' : '- ';
            $items[] = $prefix . $itemStr;
        }

        return implode("
", $items);
    }

    private function renderComparisonText(): string
    {
        $content = $this->getArrayContent();
        $bad = (string) ($content['bad'] ?? '');
        $good = (string) ($content['good'] ?? '');

        return "📢 BAD:
{$bad}

 GOOD:
{$good}";
    }
}
