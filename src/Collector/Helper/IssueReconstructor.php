<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector\Helper;

use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\CodeSuggestion;
use AhmedBhs\DoctrineDoctor\Suggestion\ModernSuggestion;
use AhmedBhs\DoctrineDoctor\Suggestion\StructuredSuggestion;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionRendererInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

/**
 * Helper for reconstructing issues from serialized data.
 * Extracted from DoctrineDoctorDataCollector to reduce complexity.
 */
final class IssueReconstructor
{
    public function __construct(
        /**
         * @readonly
         */
        private ?SuggestionRendererInterface $templateRenderer = null,
    ) {
    }

    /**
     * Reconstruct issue object from serialized data.
     */
    public function reconstructIssue(array $issueData): IssueInterface
    {
        $issueClass = $issueData['class'];
        $suggestion = null;

        if (isset($issueData['suggestion']) && is_array($issueData['suggestion'])) {
            $suggestion = $this->reconstructSuggestion($issueData['suggestion']);
        }

        // Extract duplicated issues before passing to constructor
        $duplicatedIssuesData = $issueData['duplicatedIssues'] ?? [];

        // Remove 'class', 'suggestion', and 'duplicatedIssues' from issueData before passing to constructor
        unset($issueData['class'], $issueData['suggestion'], $issueData['duplicatedIssues']);

        // Reconstruct the issue object
        $issue = new $issueClass(array_merge($issueData, ['suggestion' => $suggestion]));
        assert($issue instanceof IssueInterface, 'Issue class must implement IssueInterface');

        // Reconstruct and attach duplicated issues if any
        if (count($duplicatedIssuesData) > 0) {
            $duplicatedIssues = array_map(
                fn (array $dupData) => $this->reconstructSimplifiedIssue($dupData),
                $duplicatedIssuesData,
            );
            $issue->setDuplicatedIssues($duplicatedIssues);
        }

        return $issue;
    }

    /**
     * Reconstruct a simplified issue from minimal data (for duplicated issues).
     * Duplicated issues are stored with minimal data: title, type, severity, description excerpt.
     */
    private function reconstructSimplifiedIssue(array $data): IssueInterface
    {
        // Use a simple PerformanceIssue for reconstructed duplicates
        // We only need title, type, severity, and description excerpt for display
        $issueClass = \AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue::class;

        $title = $data['title'] ?? 'Unknown Issue';

        // Use the stored description excerpt if available, otherwise generate one
        $description = $data['description'] ?? ('Duplicate issue: ' . $title);

        return new $issueClass([
            'type'        => $data['type'] ?? 'unknown',
            'title'       => $title,
            'description' => $description,
            'severity'    => $data['severity'] ?? 'info',
            'queries'     => [],
        ]);
    }

    /**
     * Reconstruct suggestion object from serialized data.
     */
    private function reconstructSuggestion(array $suggestionData): SuggestionInterface
    {
        $suggestionClass = $suggestionData['class'];
        unset($suggestionData['class']);

        // Special handling for ModernSuggestion
        if (ModernSuggestion::class === $suggestionClass) {
            return $this->reconstructModernSuggestion($suggestionData);
        }

        // Special handling for StructuredSuggestion
        if (StructuredSuggestion::class === $suggestionClass) {
            return StructuredSuggestion::fromArray($suggestionData);
        }

        // For legacy suggestions, pass the array directly
        $suggestion = new $suggestionClass($suggestionData);
        assert($suggestion instanceof SuggestionInterface, 'Suggestion class must implement SuggestionInterface');
        return $suggestion;
    }

    /**
     * Reconstruct ModernSuggestion from serialized data.
     */
    private function reconstructModernSuggestion(array $data): SuggestionInterface
    {
        // If the data already has rendered code/description, use CodeSuggestion
        if (isset($data['code']) && isset($data['description'])) {
            return new CodeSuggestion([
                'code'        => $data['code'],
                'description' => $data['description'],
            ]);
        }

        // Reconstruct SuggestionMetadata
        $metadataArray = $data['metadata'] ?? [];

        $typeValue = $metadataArray['type'] ?? 'performance';
        $type      = match ($typeValue) {
            'performance'   => SuggestionType::performance(),
            'security'      => SuggestionType::security(),
            'configuration' => SuggestionType::configuration(),
            'integrity'     => SuggestionType::integrity(),
            'best_practice' => SuggestionType::bestPractice(),
            'refactoring'   => SuggestionType::refactoring(),
            default         => SuggestionType::performance(),
        };

        $severityValue = $metadataArray['severity'] ?? 'warning';
        $severity      = Severity::fromString($severityValue);

        $suggestionMetadata = new SuggestionMetadata(
            type: $type,
            severity: $severity,
            title: $metadataArray['title'] ?? 'Suggestion',
            tags: $metadataArray['tags'] ?? [],
        );

        return new ModernSuggestion(
            templateName: $data['template'] ?? 'default',
            context: $data['context'] ?? [],
            suggestionMetadata: $suggestionMetadata,
            suggestionRenderer: $this->templateRenderer,
        );
    }
}
