<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Suggestion;

use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

class QueryOptimizationSuggestion implements SuggestionInterface
{
    private readonly string $code;

    private readonly string $description;

    public function __construct(array $data)
    {
        $this->code        = $data['code'] ?? '';
        $this->description = $data['description'] ?? $data['optimization'] ?? '';
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getMetadata(): SuggestionMetadata
    {

        return new SuggestionMetadata(
            type: SuggestionType::performance(),
            severity: Severity::WARNING,
            title: 'Optimize query performance',
            tags: ['performance', 'optimization'],
        );

    }

    public function toArray(): array
    {
        return [
            'class'       => static::class,
            'code'        => $this->code,
            'description' => $this->description,
        ];
    }
}
