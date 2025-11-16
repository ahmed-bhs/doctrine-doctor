<?php

declare(strict_types=1);

/**
 * Template for Table Naming Convention suggestions.
 * Context variables:
 * @var string $current - Current table name
 * @var string $suggested - Suggested table name
 * @var string $entity_class - Short entity class name
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
$current = $context['current'] ?? null;
$suggested = $context['suggested'] ?? null;
$entityClass = $context['entity_class'] ?? 'Entity';

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Fix Table Naming</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Table naming violation:</strong> '<?php echo $e($current); ?>' should be '<?php echo $e($suggested); ?>'
    </div>

    <h4>Current</h4>
    <pre><code class="language-php">#[ORM\Table(name: '<?php echo $e($current); ?>')]
class <?php echo $e($entityClass); ?> {}</code></pre>

    <h4>Recommended</h4>
    <pre><code class="language-php">#[ORM\Table(name: '<?php echo $e($suggested); ?>')]
class <?php echo $e($entityClass); ?> {}</code></pre>

    <p><strong>Convention:</strong> snake_case, plural (users, order_items). Avoid SQL keywords. Create migration with <code>make:migration</code>.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/naming-strategy.html" target="_blank" class="doc-link">
            📖 Doctrine Naming Strategy Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        "Rename table from '%s' to '%s'",
        $current,
        $suggested,
    ),
];
