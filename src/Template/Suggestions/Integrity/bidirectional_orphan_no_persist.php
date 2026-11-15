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
<?php echo suggestionHeader("Add cascade: ['persist'] next to orphanRemoval"); ?>
<div class="suggestion-content">
<p><code><?php echo $e($parentClass); ?>::$<?php echo $e($parentField); ?></code> deletes children removed from the collection, but new children still need an explicit <code>persist()</code>. A parent that owns its children usually handles both.</p>

<div class="query-item"><pre><code class="language-php">class <?php echo $e($parentClass); ?>

{
    #[ORM\OneToMany(
        mappedBy: 'parent', // keep your current mappedBy
        cascade: ['persist', 'remove'], // was missing 'persist'
        orphanRemoval: true,
    )]
    private Collection $<?php echo $e($parentField); ?>;
}</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/working-with-associations.html#orphan-removal', 'Doctrine ORM Orphan Removal'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Add cascade="persist" next to orphanRemoval',
];
