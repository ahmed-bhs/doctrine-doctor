<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Template\Renderer;

use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionRendererInterface;
use RuntimeException;

/**
 * Template renderer that uses in-memory templates (for backward compatibility).
 * Templates are registered as callables that return code and description.
 * Implements SuggestionRendererInterface (Domain) following DIP.
 */
class InMemoryTemplateRenderer implements TemplateRendererInterface, SuggestionRendererInterface
{
    /**
     * @var array<string, callable>
     */
    private array $templates = [];

    /**
     * Register a template.
     * @param string   $name     Template identifier
     * @param callable $template Callable that receives context and returns ['code' => string, 'description' => string]
     */
    public function registerTemplate(string $name, callable $template): void
    {
        $this->templates[$name] = $template;
    }

    /**
     * @return array<mixed>
     */
    public function render(string $templateName, array $context): array
    {
        if (!$this->exists($templateName)) {
            throw new RuntimeException(sprintf('Template not registered: %s', $templateName));
        }

        $result = ($this->templates[$templateName])($context);

        if (!is_array($result) || !isset($result['code'], $result['description'])) {
            throw new RuntimeException(sprintf('Template %s must return an array with "code" and "description" keys', $templateName));
        }

        return [
            'code'        => (string) $result['code'],
            'description' => (string) $result['description'],
        ];
    }

    public function exists(string $templateName): bool
    {
        return isset($this->templates[$templateName]);
    }

    /**
     * Get all registered template names.
     * @return array<string>
     */
    public function getRegisteredTemplates(): array
    {
        return array_values(array_map(fn (string $key): string => $key, array_keys($this->templates)));
    }
}
