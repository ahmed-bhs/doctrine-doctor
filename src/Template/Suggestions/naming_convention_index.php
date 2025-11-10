<?php

declare(strict_types=1);

/**
 * Template for Index Naming Convention suggestions.
 * Context variables:
 * @var string $current - Current index name
 * @var string $suggested - Suggested index name
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
$current = $context['current'] ?? null;
$suggested = $context['suggested'] ?? null;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
        <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/>
    </svg>
    <h4>Fix Index Naming Convention</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Index naming convention violation detected.</strong>
    </div>

    <h4>Current</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Index(name: '<?php echo $e($current); ?>', columns: ['...'])]</code></pre>
    </div>

    <h4> Recommended</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Index(name: '<?php echo $e($suggested); ?>', columns: ['...'])]</code></pre>
    </div>

    <h4>Index/Constraint conventions</h4>
    <ul>
        <li>Regular indexes: idx_{columns} (idx_email, idx_status_created_at)</li>
        <li>Unique constraints: uniq_{columns} (uniq_email, uniq_username)</li>
        <li>Use snake_case</li>
        <li>Include column names in index name for clarity</li>
    </ul>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#index" target="_blank" class="doc-link">
            📖 Doctrine Index Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        "Rename index from '%s' to '%s'",
        $current,
        $suggested,
    ),
];
