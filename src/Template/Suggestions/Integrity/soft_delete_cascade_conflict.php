<?php

declare(strict_types=1);

$entityClass = $context['entity_class'] ?? '';
$entityClass = is_string($entityClass) ? $entityClass : '';
$fieldName = $context['field_name'] ?? '';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$shortClass = basename(str_replace('\\', '/', $entityClass));

ob_start();
?>
<?php echo suggestionHeader('Drop onDelete CASCADE on soft-deleted rows'); ?>
<div class="suggestion-content">
<p><code><?php echo $e($shortClass); ?>::$<?php echo $e($fieldName); ?></code> uses <code>onDelete: 'CASCADE'</code> on a soft-deleteable entity. A soft delete is an UPDATE, so the cascade never fires then, and a hard delete physically removes children that soft delete was meant to keep.</p>

<div class="query-item"><pre><code class="language-php">// Before
#[ORM\JoinColumn(onDelete: 'CASCADE')]
private ?Category $<?php echo $e($fieldName); ?>;

// After
#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
private ?Category $<?php echo $e($fieldName); ?>;</code></pre></div>

<p>To soft-delete the children with their parent, use an ORM <code>cascade: ['remove']</code> instead: Gedmo turns each removal into a soft delete.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/working-with-associations.html#transitive-persistence-cascade-operations', 'Doctrine ORM Cascade Operations'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Replace onDelete CASCADE on %s::%s, which conflicts with soft delete', $shortClass, $fieldName),
];
