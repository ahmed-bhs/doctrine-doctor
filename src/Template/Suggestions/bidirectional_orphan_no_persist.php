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
    <h4>orphanRemoval without cascade="persist"</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <code><?php echo $e($parentField); ?></code> has <code>orphanRemoval=true</code> but no <code>cascade="persist"</code>. You can delete children but not automatically save new ones.
    </div>

    <p>With orphanRemoval but no cascade persist, removing children from the collection will delete them, but adding new children requires manual persist(). This is usually not what you want for a composition relationship.</p>

    <h4>Fix</h4>
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

    <p>For full composition (Order → OrderItems), use <code>cascade={"persist", "remove"}</code> with <code>orphanRemoval=true</code>. This way both adding and removing children works automatically.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Add cascade="persist" with orphanRemoval for full composition',
];
