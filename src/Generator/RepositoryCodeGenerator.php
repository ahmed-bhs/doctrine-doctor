<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Generator;

use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;

class RepositoryCodeGenerator
{
    /**
     * Generates optimized repository code based on a detected issue or suggestion.
     * @param IssueInterface|SuggestionInterface $item the issue or suggestion to generate code for
     * @return string the generated optimized repository code
     */
    public function generateOptimizedRepositoryCode(object $item): string
    {
        if ($item instanceof IssueInterface) {
            $description = $item->getDescription();
            $suggestion  = $item->getSuggestion();

            if ($suggestion instanceof SuggestionInterface) {
                return sprintf(
                    "// Optimized code for issue: %s
// Suggestion: %s
// Example: %s",
                    $description,
                    $suggestion->getDescription(),
                    $suggestion->getCode(),
                );
            }

            return sprintf("// Optimized code for issue: %s
// No specific code suggestion available.", $description);
        }

        if ($item instanceof SuggestionInterface) {
            return sprintf(
                "// Optimized code based on suggestion: %s
// Example: %s",
                $item->getDescription(),
                $item->getCode(),
            );
        }

        return '// Unable to generate optimized code for the provided item.';
    }
}
