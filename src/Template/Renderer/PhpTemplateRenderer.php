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
use AhmedBhs\DoctrineDoctor\Template\Security\SafeContext;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Template renderer using PHP-based templates.
 * Templates are PHP files that return an array with 'code' and 'description'.
 * implements SuggestionRendererInterface (Domain)
 * following the Dependency Inversion Principle.
 */
final class PhpTemplateRenderer implements TemplateRendererInterface, SuggestionRendererInterface
{
    /**
     * @readonly
     */
    private string $templateDirectory;

    public function __construct(
        ?string $templateDirectory = null,
        /**
         * @readonly
         */
        private ?LoggerInterface $logger = null,
    ) {
        $this->templateDirectory = $templateDirectory ?? $this->getDefaultTemplateDirectory();

        if (!is_dir($this->templateDirectory)) {
            $this->logger?->critical('Template directory does not exist', [
                'directory' => $this->templateDirectory,
            ]);

            throw new InvalidArgumentException(sprintf('Template directory does not exist: %s', $this->templateDirectory));
        }
    }

    /**
     * @return array<mixed>
     */
    public function render(string $templateName, array $context): array
    {
        $templatePath = $this->getTemplatePath($templateName);

        if (!file_exists($templatePath)) {
            $this->logger?->error('Template not found', [
                'template' => $templateName,
                'path'     => $templatePath,
            ]);

            throw new RuntimeException(sprintf('Template not found: %s (path: %s)', $templateName, $templatePath));
        }

        try {
            // Render template in isolated scope with safe context
            $render = function (string $__templatePath, array $__context) {
                // Include helper functions for templates
                require_once dirname(__DIR__) . '/helpers.php';

                // Create safe context wrapper for auto-escaping
                // Templates can access:
                // - $context->key (auto-escaped)
                // - $context->raw('key') (unescaped)
                // - $context['key'] (auto-escaped)
                $context = new SafeContext($__context);

                // Also extract raw variables for backward compatibility
                // with existing templates that use $variableName directly
                extract($__context, EXTR_SKIP);

                // Track output buffer level before template execution
                $bufferLevelBefore = ob_get_level();

                try {
                    return require $__templatePath;
                } finally {
                    // CRITICAL: Clean any orphaned output buffers created by the template
                    // This prevents HTML buffer leaks when template errors occur between
                    // ob_start() and ob_get_clean()
                    while (ob_get_level() > $bufferLevelBefore) {
                        ob_end_clean();
                    }
                }
            };

            $result = $render($templatePath, $context);

            if (!is_array($result) || !isset($result['code'], $result['description'])) {
                $this->logger?->critical('Template returned invalid format', [
                    'template'    => $templateName,
                    'result_type' => get_debug_type($result),
                    'result_keys' => is_array($result) ? array_keys($result) : [],
                ]);

                throw new RuntimeException(sprintf('Template %s must return an array with "code" and "description" keys', $templateName));
            }

            return [
                'code'        => (string) $result['code'],
                'description' => (string) $result['description'],
            ];
        } catch (\Throwable $throwable) {
            $this->logger?->error('Template rendering failed', [
                'template'     => $templateName,
                'exception'    => $throwable->getMessage(),
                'file'         => $throwable->getFile(),
                'line'         => $throwable->getLine(),
                'context_keys' => array_keys($context),
            ]);

            throw $throwable;
        }
    }

    public function exists(string $templateName): bool
    {
        return file_exists($this->getTemplatePath($templateName));
    }

    private function getTemplatePath(string $templateName): string
    {
        // Sanitize template name to prevent path traversal
        // Allow forward slashes for category subdirectories (e.g., Performance/flush_in_loop)
        // but remove ../ to prevent directory traversal attacks
        $templateName = str_replace(['..'], '', $templateName);
        // Normalize backslashes to forward slashes
        $templateName = str_replace('\\', '/', $templateName);

        return $this->templateDirectory . '/' . $templateName . '.php';
    }

    private function getDefaultTemplateDirectory(): string
    {
        return dirname(__DIR__) . '/Suggestions';
    }
}
