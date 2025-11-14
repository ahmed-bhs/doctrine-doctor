<?php

declare(strict_types=1);

/**
 * Template for orphanRemoval with nullable FK inconsistency.
 * Context variables:
 */
$parentClass = $context['parent_class'] ?? 'ClassName';
$parentField = $context['parent_field'] ?? 'field';
$childClass = $context['child_class'] ?? 'ClassName';
$childField = $context['child_field'] ?? 'field';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>orphanRemoval=true with nullable FK</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <code><?php echo $e($parentField); ?></code> has <code>orphanRemoval=true</code> but
        <code><?php echo $e($childField); ?></code> has <code>nullable=true</code>.
    </div>

    <p>orphanRemoval means Doctrine should DELETE orphans, but nullable FK allows the foreign key to be NULL. This creates an inconsistency: should orphans be deleted or set to NULL?</p>

    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($parentClass); ?> {
    /** @OneToMany(orphanRemoval=true) */
    private Collection $<?php echo $e($parentField); ?>;
}

class <?php echo $e($childClass); ?> {
    /** @ManyToOne @JoinColumn(nullable=true) */
    private ?<?php echo $e($parentClass); ?> $<?php echo $e($childField); ?>;
}</code></pre>
    </div>

    <h4>Fix</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($childClass); ?> {
    /** @ManyToOne @JoinColumn(nullable=false, onDelete="CASCADE") */
    private <?php echo $e($parentClass); ?> $<?php echo $e($childField); ?>;
}</code></pre>
    </div>

    <p>Make the FK NOT NULL to match orphanRemoval=true. Children can't exist without a parent, so the FK should never be NULL.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/working-with-associations.html#orphan-removal" target="_blank" class="doc-link">
            📖 Doctrine Orphan Removal Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Make FK NOT NULL to be consistent with orphanRemoval=true',
];
