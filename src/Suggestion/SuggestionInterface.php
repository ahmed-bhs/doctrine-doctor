<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Suggestion;

use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;

interface SuggestionInterface
{
    public function getCode(): string;

    public function getDescription(): string;

    public function getMetadata(): SuggestionMetadata;

    public function toArray(): array;
}
