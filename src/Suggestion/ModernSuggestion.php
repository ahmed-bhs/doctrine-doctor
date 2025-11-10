<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Suggestion;

use AhmedBhs\DoctrineDoctor\ValueObject\RenderedSuggestion;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use Webmozart\Assert\Assert;

/**
 * Template-based suggestion implementation.
 */
final readonly class ModernSuggestion implements SuggestionInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $templateName,
        /** @var array<mixed> */
        private array $context,
        private SuggestionMetadata $suggestionMetadata,
        private ?SuggestionRendererInterface $suggestionRenderer = null,
    ) {
        Assert::stringNotEmpty($templateName, 'Template name cannot be empty');
    }

    /**
     * Render the suggestion using the suggestion renderer.
     */
    public function render(SuggestionRendererInterface $suggestionRenderer): RenderedSuggestion
    {
        $rendered = $suggestionRenderer->render($this->templateName, $this->context);

        return new RenderedSuggestion(
            code: $rendered['code'],
            description: $rendered['description'],
            metadata: $this->suggestionMetadata,
        );
    }

    /**
     * Get the suggestion code (lazy rendering with default renderer).
     * For backward compatibility with SuggestionInterface.
     */
    public function getCode(): string
    {
        if (!$this->suggestionRenderer instanceof SuggestionRendererInterface) {
            return $this->renderFallback();
        }

        try {
            $rendered = $this->suggestionRenderer->render($this->templateName, $this->context);

            return $rendered['code'];
        } catch (\Throwable $throwable) {
            return $this->renderFallback($throwable->getMessage());
        }
    }

    /**
     * Get the suggestion description (lazy rendering with default renderer).
     * For backward compatibility with SuggestionInterface.
     */
    public function getDescription(): string
    {
        if (!$this->suggestionRenderer instanceof SuggestionRendererInterface) {
            return 'No renderer available. ' . $this->suggestionMetadata->title;
        }

        $rendered = $this->suggestionRenderer->render($this->templateName, $this->context);

        return $rendered['description'];
    }

    public function getMetadata(): SuggestionMetadata
    {
        return $this->suggestionMetadata;
    }

    public function getTemplateName(): string
    {
        return $this->templateName;
    }

    /**
     * @return array<mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        $base = [
            'class'    => self::class,
            'template' => $this->templateName,
            'context'  => $this->context,
            'metadata' => $this->suggestionMetadata->toArray(),
        ];

        // Try to render if renderer is available
        if ($this->suggestionRenderer instanceof SuggestionRendererInterface) {
            try {
                $rendered            = $this->suggestionRenderer->render($this->templateName, $this->context);
                $base['code']        = $rendered['code'];
                $base['description'] = $rendered['description'];
            } catch (\Throwable $e) {
                $base['render_error'] = $e->getMessage();
            }
        }

        return $base;
    }

    /**
     * Render a fallback HTML when template rendering fails.
     */
    private function renderFallback(?string $error = null): string
    {
        $title    = htmlspecialchars($this->suggestionMetadata->title);
        $errorMsg = null !== $error ? htmlspecialchars($error) : 'No template renderer available';

        $contextHtml = '';

        foreach ($this->context as $key => $value) {
            $key   = htmlspecialchars((string) $key);
            $valueStr = is_scalar($value) ? (string) $value : (json_encode($value) ?: 'N/A');
            $value = htmlspecialchars($valueStr);
            $contextHtml .= sprintf('<li><strong>%s:</strong> %s</li>', $key, $value);
        }

        return <<<HTML
            <div class="alert alert-warning" style="margin: 16px; padding: 14px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 6px;">
                <p style="margin: 0 0 8px 0;"><strong>Unable to Render Suggestion</strong></p>
                <p style="margin: 0 0 12px 0;"><em>Template:</em> <code>{$this->templateName}</code></p>
                <p style="margin: 0 0 12px 0;"><em>Error:</em> {$errorMsg}</p>
                <p style="margin: 0 0 8px 0;"><strong>Suggestion Details:</strong></p>
                <ul style="margin: 0; padding-left: 20px;">
                    <li><strong>Title:</strong> {$title}</li>
                    {$contextHtml}
                </ul>
                <p style="margin: 12px 0 0 0; font-size: 12px; color: #856404;">
                    <em>This analyzer needs to be updated to use the new structured suggestion system.</em>
                </p>
            </div>
            HTML;
    }
}
