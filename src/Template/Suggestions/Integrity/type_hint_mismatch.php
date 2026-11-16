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
<?php echo suggestionHeader('Match the property type to the column'); ?>

<div class="suggestion-content">
    <p><?php echo $e($description); ?> The UnitOfWork compares values with <code>===</code>, so a mismatched type looks changed on every flush and triggers an UPDATE.</p>

    <div class="query-item">
        <pre><code class="language-php">// Before
<?php echo $e($badCode); ?>


// After
<?php echo $e($goodCode); ?></code></pre>
    </div>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/basic-mapping.html', 'Doctrine basic mapping docs'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Match the property type to the column type to avoid needless UPDATEs',
];
