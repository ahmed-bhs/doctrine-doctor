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

readonly class ConfigurationSuggestion implements SuggestionInterface
{
    public function __construct(
        /** @var array<mixed> */
        private array $data,
    ) {
    }

    public function getDescription(): string
    {
        $setting          = $this->data['setting'] ?? 'Unknown';
        $currentValue     = $this->data['current_value'] ?? 'N/A';
        $recommendedValue = $this->data['recommended_value'] ?? 'N/A';

        return sprintf("Configuration issue: %s is set to '%s' but recommended value is '%s'", $setting, $currentValue, $recommendedValue);
    }

    public function getCode(): string
    {
        $setting          = $this->data['setting'] ?? 'Unknown';
        $currentValue     = $this->data['current_value'] ?? 'N/A';
        $recommendedValue = $this->data['recommended_value'] ?? 'N/A';
        $description      = $this->data['description'] ?? '';

        $code = "# Current configuration issue:
";
        $code .= sprintf('# Setting: %s%s', $setting, PHP_EOL);
        $code .= sprintf('# Current value: %s%s', $currentValue, PHP_EOL);
        $code .= "# Recommended value: {$recommendedValue}

";

        if ('' !== $description) {
            $code .= "# {$description}

";
        }

        if (isset($this->data['fix_command'])) {
            $code .= "# To fix, run:
";
            $code .= $this->data['fix_command'];
        }

        return $code;
    }

    public function getMetadata(): SuggestionMetadata
    {
        return new SuggestionMetadata(
            type: SuggestionType::CONFIGURATION,
            severity: Severity::WARNING,
            title: 'Configuration Optimization',
            tags: ['configuration'],
        );
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'class'             => self::class,
            'setting'           => $this->data['setting'] ?? 'Unknown',
            'current_value'     => $this->data['current_value'] ?? 'N/A',
            'recommended_value' => $this->data['recommended_value'] ?? 'N/A',
            'description'       => $this->data['description'] ?? '',
            'fix_command'       => $this->data['fix_command'] ?? null,
        ];
    }
}
