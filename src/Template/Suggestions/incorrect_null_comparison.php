<?php

declare(strict_types=1);

/**
 * Template for Incorrect NULL Comparison suggestion.
 * Context variables:
 * @var string $bad_code - Example of incorrect code
 * @var string $good_code - Example of correct code
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
['bad_code' => $badCode, 'good_code' => $goodCode] = $context;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
    </svg>
    <h4>Use IS NULL instead of = NULL</h4>
</div>

<div class="suggestion-content">
    <p>SQL NULL comparisons require IS NULL or IS NOT NULL operators. Direct equality comparisons with NULL always return NULL (unknown), not true or false.</p>

    <h4>Current Code (Incorrect)</h4>
    <div class="query-item">
        <pre><code class="language-php"><?php echo $e($badCode); ?></code></pre>
    </div>

    <div class="alert alert-warning">
        Using <code>= NULL</code> or <code>!= NULL</code> will NOT work. The condition never matches rows because <code>= NULL</code> returns UNKNOWN, not TRUE.
    </div>

    <h4>Correct Code</h4>
    <div class="query-item">
        <pre><code class="language-php"><?php echo $e($goodCode); ?></code></pre>
    </div>

    <p><code>IS NULL</code> and <code>IS NOT NULL</code> are the SQL standard. <code>= NULL</code> always returns UNKNOWN (three-valued logic), never TRUE or FALSE.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/query-builder.html#helper-methods" target="_blank" class="doc-link">
            📖 Doctrine QueryBuilder Helper Methods →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use IS NULL instead of = NULL for correct SQL NULL comparisons',
];
