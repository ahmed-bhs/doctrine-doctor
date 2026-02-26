<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Factory;

use AhmedBhs\DoctrineDoctor\Suggestion\ModernSuggestion;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionRendererInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use RuntimeException;
use Webmozart\Assert\Assert;

final readonly class SuggestionFactory implements SuggestionFactoryInterface
{
    public function __construct(
        private SuggestionRendererInterface $suggestionRenderer,
    ) {
        Assert::isInstanceOf($suggestionRenderer, SuggestionRendererInterface::class);
    }

    /**
     * @param array<mixed> $context
     * @throws \RuntimeException if template file does not exist
     */
    public function createFromTemplate(
        string $templateName,
        array $context,
        SuggestionMetadata $suggestionMetadata,
    ): SuggestionInterface {
        $templatePath = __DIR__ . '/../Template/Suggestions/' . $templateName . '.php';

        if (!file_exists($templatePath)) {
            $categories = ['Performance', 'Security', 'Integrity', 'Configuration'];
            $found = false;

            foreach ($categories as $category) {
                $categoryPath = __DIR__ . '/../Template/Suggestions/' . $category . '/' . $templateName . '.php';
                if (file_exists($categoryPath)) {
                    $templatePath = $categoryPath;
                    $templateName = $category . '/' . $templateName;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                throw new RuntimeException(sprintf('Template file "%s.php" does not exist. Create it in src/Template/Suggestions/ or in a category subdirectory (Performance, Security, CodeQuality, Configuration)', $templateName));
            }
        }

        return new ModernSuggestion(
            $templateName,
            $context,
            $suggestionMetadata,
            $this->suggestionRenderer,
        );
    }
}
