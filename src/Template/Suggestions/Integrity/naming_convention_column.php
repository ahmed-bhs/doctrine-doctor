<?php

declare(strict_types=1);

/**
 * Template for Column Naming Convention suggestions.
 * Context variables:
 * @var string $current - Current column name
 * @var string $suggested - Suggested column name
 * @var string $field_name - Field name in entity
 * @var string $entity_class - Short entity class name
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
$current = $context['current'] ?? null;
$suggested = $context['suggested'] ?? null;
$fieldName = $context['field_name'] ?? 'field';
$entityClass = $context['entity_class'] ?? 'Entity';

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Fix Column Naming</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Column naming violation:</strong> '<?php echo $e($current); ?>' should be '<?php echo $e($suggested); ?>'
    </div>

    <h4>Current</h4>
    <pre><code class="language-php">#[ORM\Column(name: '<?php echo $e($current); ?>')]
private $<?php echo $e($fieldName); ?>;</code></pre>

    <h4>Recommended</h4>
    <pre><code class="language-php">#[ORM\Column(name: '<?php echo $e($suggested); ?>')]
private $<?php echo $e($fieldName); ?>;</code></pre>

    <p><strong>Convention:</strong> snake_case, lowercase (first_name, created_at). Avoid SQL keywords.</p>

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
        "Rename column from '%s' to '%s'",
        $current,
        $suggested,
    ),
];
