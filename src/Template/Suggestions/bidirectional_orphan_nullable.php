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
    <h4>Inconsistency: orphanRemoval=true with nullable FK</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Bidirectional Association Inconsistency</strong><br>
        <code><?php echo $e($parentField); ?></code> has <code>orphanRemoval=true</code> but
        <code><?php echo $e($childField); ?></code> in <code><?php echo $e($childClass); ?></code> has <code>nullable=true</code>.
    </div>

    <h4>📢 Current Configuration</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($parentClass); ?> {
    /** @OneToMany(orphanRemoval=true) */
    private Collection $<?php echo $e($parentField); ?>;
}

class <?php echo $e($childClass); ?> {
    /** @ManyToOne @JoinColumn(nullable=true) */  //  Inconsistent!
    private ?<?php echo $e($parentClass); ?> $<?php echo $e($childField); ?>;
}</code></pre>
    </div>

    <h4>Problem</h4>
    <p>
        <code>orphanRemoval=true</code> means Doctrine should DELETE orphans,
        but <code>nullable=true</code> allows the FK to be set to NULL.
        This creates an inconsistency: should orphans be deleted or set to NULL?
    </p>

    <h4> Solution: Make FK NOT NULL</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($childClass); ?> {
    /** @ManyToOne @JoinColumn(nullable=false, onDelete="CASCADE") */
    private <?php echo $e($parentClass); ?> $<?php echo $e($childField); ?>;
}</code></pre>
    </div>

    <div class="alert alert-info">
        ℹ️ <strong>Rule:</strong> orphanRemoval=true requires nullable=false
    </div>

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
