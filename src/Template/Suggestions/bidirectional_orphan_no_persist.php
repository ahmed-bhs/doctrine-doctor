<?php

declare(strict_types=1);

/**
 * Template for orphanRemoval without cascade="persist".
 */
$parentClass = $context['parent_class'] ?? 'ClassName';
$parentField = $context['parent_field'] ?? 'field';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>orphanRemoval without cascade="persist" Is Incomplete</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Incomplete Configuration</strong><br>
        <code><?php echo $e($parentField); ?></code> has <code>orphanRemoval=true</code> but no <code>cascade="persist"</code>.<br>
        You can delete children but not automatically save new ones!
    </div>

    <h4>Problem</h4>
    <p>
        With <code>orphanRemoval</code> but no <code>cascade="persist"</code>:
    </p>
    <ul>
        <li> Removing children from collection will delete them</li>
        <li>📢 Adding new children requires manual persist()</li>
    </ul>

    <h4> Solution: Add cascade="persist" for Full Composition</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($parentClass); ?> {
    /**
     * @OneToMany(
     *     cascade={"persist", "remove"},
     *     orphanRemoval=true
     * )
     */
    private Collection $<?php echo $e($parentField); ?>;
}</code></pre>
    </div>

    <h4>Full Composition Relationships</h4>
    <p>
        For true parent-child composition (Order → OrderItems):
    </p>
    <ul>
        <li><code>cascade={"persist", "remove"}</code></li>
        <li><code>orphanRemoval=true</code></li>
        <li><code>nullable=false</code> on child FK</li>
    </ul>

    <div class="alert alert-info">
        ℹ️ <strong>Rule:</strong> orphanRemoval usually needs cascade="persist" for full composition
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Add cascade="persist" with orphanRemoval for full composition',
];
