<?php

declare(strict_types=1);

/**
 * Template for Foreign Key Naming Convention suggestions.
 * Context variables:
 * @var string $current - Current FK column name
 * @var string $suggested - Suggested FK column name
 * @var string $assoc_name - Association field name
 * @var string $entity_class - Short entity class name
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
$current = $context['current'] ?? null;
$suggested = $context['suggested'] ?? null;
$assocName = $context['assoc_name'] ?? null;
$entityClass = $context['entity_class'] ?? 'Entity';

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Fix Foreign Key Naming</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>FK naming violation:</strong> '<?php echo $e($current); ?>' should be '<?php echo $e($suggested); ?>'
    </div>

    <h4>Current</h4>
    <pre><code class="language-php">#[ORM\JoinColumn(name: '<?php echo $e($current); ?>')]
private $<?php echo $e($assocName); ?>;</code></pre>

    <h4>Recommended</h4>
    <pre><code class="language-php">#[ORM\JoinColumn(name: '<?php echo $e($suggested); ?>')]
private $<?php echo $e($assocName); ?>;</code></pre>

    <p><strong>Convention:</strong> snake_case with _id suffix (user_id, product_id).</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#joincolumn" target="_blank" class="doc-link">
            📖 Doctrine JoinColumn Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        "Rename foreign key from '%s' to '%s'",
        $current,
        $suggested,
    ),
];
