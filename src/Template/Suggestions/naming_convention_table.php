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
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
        <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/>
    </svg>
    <h4>Fix Table Naming Convention</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Table naming convention violation detected.</strong>
    </div>

    <h4>Current</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Table(name: '<?php echo $e($current); ?>')]
class <?php echo $e($entityClass); ?>
{
    // ...
}</code></pre>
    </div>

    <h4> Recommended</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Table(name: '<?php echo $e($suggested); ?>')]
class <?php echo $e($entityClass); ?>
{
    // ...
}</code></pre>
    </div>

    <h4>Doctrine/Symfony conventions</h4>
    <ul>
        <li>Tables: snake_case, plural (users, order_items, product_categories)</li>
        <li>Avoid SQL reserved keywords (order, group, user, etc.)</li>
        <li>Use only: letters, numbers, underscores</li>
    </ul>

    <h4>After fixing</h4>
    <ol>
        <li>Create migration: <code>php bin/console make:migration</code></li>
        <li>Review migration to rename table</li>
        <li>Run migration: <code>php bin/console doctrine:migrations:migrate</code></li>
    </ol>

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
