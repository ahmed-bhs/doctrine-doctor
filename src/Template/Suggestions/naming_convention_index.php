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

    <h4>Recommended</h4>
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
