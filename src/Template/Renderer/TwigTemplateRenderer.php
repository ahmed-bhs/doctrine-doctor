<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Template\Renderer;

use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionRendererInterface;
use RuntimeException;
use Twig\Environment;
use Twig\Error\Error as TwigError;

/**
 * Template renderer using Twig template engine.
 * Useful for testing and when Twig templates are preferred.
 * Implements SuggestionRendererInterface (Domain) following DIP.
 */
final class TwigTemplateRenderer implements TemplateRendererInterface, SuggestionRendererInterface
{
    public function __construct(
        /**
         * @readonly
         */
        private Environment $twigEnvironment,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array{code: string, description: string}
     */
    public function render(string $templateName, array $context): array
    {
        if (!$this->exists($templateName)) {
            throw new RuntimeException(sprintf('Template not found: %s', $templateName));
        }

        try {
            $rendered = $this->twigEnvironment->render($templateName, $context);

            // For simple test templates, return the rendered content as description
            // In production, Twig templates would return structured data
            return [
                'code' => $context['code'] ?? '',
                'description' => $rendered,
            ];
        } catch (TwigError $twigError) {
            throw new RuntimeException(sprintf('Failed to render template %s: %s', $templateName, $twigError->getMessage()), 0, $twigError);
        }
    }

    public function exists(string $templateName): bool
    {
        return $this->twigEnvironment->getLoader()->exists($templateName);
    }
}
