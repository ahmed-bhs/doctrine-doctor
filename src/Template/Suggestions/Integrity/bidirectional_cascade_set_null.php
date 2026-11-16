<?php

declare(strict_types=1);

/**
 * Template for cascade="remove" with onDelete="SET NULL" inconsistency.
 */
$parentClass = $context['parent_class'] ?? 'ClassName';
$parentField = $context['parent_field'] ?? 'field';
$childClass = $context['child_class'] ?? 'ClassName';
$childField = $context['child_field'] ?? 'field';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader("Align onDelete with cascade: ['remove']"); ?>
<div class="suggestion-content">
<p><code><?php echo $e($parentClass); ?>::$<?php echo $e($parentField); ?></code> deletes its children through <code>cascade: ['remove']</code>, but the foreign key on <code><?php echo $e($childClass); ?>::$<?php echo $e($childField); ?></code> uses <code>onDelete: 'SET NULL'</code>. A delete through the ORM removes the children, a delete in SQL keeps them with a NULL parent.</p>

<div class="query-item"><pre><code class="language-php">class <?php echo $e($childClass); ?>

{
    #[ORM\ManyToOne(inversedBy: '<?php echo $e($parentField); ?>')]
    // Before: #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private <?php echo $e($parentClass); ?> $<?php echo $e($childField); ?>;
}</code></pre></div>

<p>If children must outlive their parent, remove <code>cascade: ['remove']</code> from <code>$<?php echo $e($parentField); ?></code> instead.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/working-with-associations.html#transitive-persistence-cascade-operations', 'Doctrine ORM Cascade Operations'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use onDelete="CASCADE" to match cascade="remove"',
];
