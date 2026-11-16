<?php

declare(strict_types=1);

/**
 * Template for orphanRemoval with nullable FK inconsistency.
 */
$parentClass = $context['parent_class'] ?? 'ClassName';
$parentField = $context['parent_field'] ?? 'field';
$childClass = $context['child_class'] ?? 'ClassName';
$childField = $context['child_field'] ?? 'field';

$severity = (string) ($context['severity'] ?? 'info');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Make the foreign key NOT NULL'); ?>
<div class="suggestion-content">
<div class="alert <?php echo severityAlertClass($severity); ?>"><code><?php echo $e($parentClass); ?>::$<?php echo $e($parentField); ?></code> uses <code>orphanRemoval: true</code>, but <code><?php echo $e($childClass); ?>::$<?php echo $e($childField); ?></code> is nullable. A child cannot exist without its parent, so its foreign key should never be NULL.</div>

<div class="query-item"><pre><code class="language-php">class <?php echo $e($childClass); ?>

{
    #[ORM\ManyToOne(inversedBy: '<?php echo $e($parentField); ?>')]
    // Before: #[ORM\JoinColumn(nullable: true)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private <?php echo $e($parentClass); ?> $<?php echo $e($childField); ?>;
}</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/working-with-associations.html#orphan-removal', 'Doctrine Orphan Removal Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Make the FK NOT NULL to align with orphanRemoval=true',
];
