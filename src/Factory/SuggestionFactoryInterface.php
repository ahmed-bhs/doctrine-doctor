<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Factory;

use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;

interface SuggestionFactoryInterface
{
    /**
     * @param array<mixed> $context
     */
    public function createFromTemplate(
        string $templateName,
        array $context,
        SuggestionMetadata $suggestionMetadata,
    ): SuggestionInterface;
}
