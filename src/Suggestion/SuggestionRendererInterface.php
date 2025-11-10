<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Suggestion;

/**
 * Domain interface for rendering suggestions.
 * This interface belongs to the Domain layer (Suggestion).
 * The Infrastructure layer (Template) will implement it.
 * This follows the Dependency Inversion Principle:
 * - Domain defines the interface
 * - Infrastructure implements the interface
 * - Domain depends on abstraction, not concrete implementation
 */
interface SuggestionRendererInterface
{
    /**
     * Render a suggestion template with context variables.
     * @param string               $templateName Template name (without .php extension)
     * @param array<string, mixed> $context      Variables to pass to the template
     * @return array{code: string, description: string} Rendered suggestion
     */
    public function render(string $templateName, array $context): array;
}
