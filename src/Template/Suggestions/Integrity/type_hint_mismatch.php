<?php

declare(strict_types=1);

/**
 * Template for General Type Hint Mismatch suggestion.
 * Context variables:
 * @var string       $bad_code - Example of incorrect code
 * @var string       $good_code - Example of correct code
 * @var string       $description - Description of the issue
 * @var array<mixed> $performance_impact - List of performance impacts
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$badCode = $context['bad_code'] ?? null;
$goodCode = $context['good_code'] ?? null;
$description = $context['description'] ?? '';
$performanceImpact = $context['performance_impact'] ?? [
    'Unnecessary UPDATE queries executed',
    'Increased database load',
    'Slower application performance',
];

// Ensure performanceImpact is an array
if (!is_array($performanceImpact)) {
    $performanceImpact = [$performanceImpact];
}

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Synchronize Property Type with Column Type</h4>
</div>

<div class="suggestion-content">
    <p><?php echo $e($description); ?></p>

    <h4>Current Code (Incorrect)</h4>
    <div class="query-item">
        <pre><code class="language-php"><?php echo $e($badCode); ?></code></pre>
    </div>

    <h4>Correct Code</h4>
    <div class="query-item">
        <pre><code class="language-php"><?php echo $e($goodCode); ?></code></pre>
    </div>

    <p>Doctrine's UnitOfWork uses strict comparison (===) to detect changes. When the property type doesn't match the column type, Doctrine thinks the value changed even when it didn't. This triggers unnecessary UPDATE statements.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/basic-mapping.html" target="_blank" class="doc-link">
            📖 Doctrine basic mapping docs
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Synchronize property type with column type to prevent unnecessary UPDATEs',
];
