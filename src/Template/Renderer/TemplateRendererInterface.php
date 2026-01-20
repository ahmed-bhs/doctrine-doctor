<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Template\Renderer;

/**
 * Interface for rendering suggestion templates.
 * Allows different implementations (file-based, Twig, in-memory, etc.).
 */
interface TemplateRendererInterface
{
    /**
     * Render a template with given context.
     * @param string               $templateName Name/identifier of the template
     * @param array<string, mixed> $context      Variables to pass to the template
     * @throws \RuntimeException If template not found or rendering fails
     * @return array{code: string, description: string} Rendered code and description
     */
    public function render(string $templateName, array $context): array;

    /**
     * Check if a template exists.
     */
    public function exists(string $templateName): bool;
}
