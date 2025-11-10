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

/**
 * Generic code suggestion for code quality improvements.
 * was missing but referenced by multiple analyzers.
 */
final readonly class CodeSuggestion implements SuggestionInterface
{
    private string $description;

    private string $code;

    private ?string $filePath;

    public function __construct(array $data)
    {
        $this->description = $data['description'] ?? 'No description provided';
        $this->code        = $data['code'] ?? '';
        $this->filePath    = $data['file_path'] ?? null;
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
            type: SuggestionType::CODE_QUALITY,
            severity: Severity::WARNING,
            title: 'Code Quality Improvement',
            tags: ['code-quality'],
        );
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'class'       => self::class,
            'description' => $this->description,
            'code'        => $this->code,
            'file_path'   => $this->filePath,
        ];
    }
}
