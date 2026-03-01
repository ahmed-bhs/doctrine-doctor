<?php

declare(strict_types=1);

$entityClass = $context['entity_class'] ?? '';
$fieldName = $context['field_name'] ?? '';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$shortClass = basename(str_replace('\\', '/', $entityClass));

ob_start();
?>
<div class="suggestion-header"><h4>CASCADE DELETE vs Soft Delete conflict</h4></div>
<div class="suggestion-content">
<div class="alert alert-danger">Your entity uses soft delete but has a relation with <code>onDelete="CASCADE"</code>. This causes data loss.</div>

<p>Soft delete keeps entities in database. CASCADE DELETE physically deletes children when parent is removed.</p>

<h4>Current</h4>
<div class="query-item"><pre><code class="language-php">#[ORM\Entity]
#[Gedmo\SoftDeleteable]
class <?php echo $e($shortClass); ?>

{
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Category $<?php echo $e($fieldName); ?>;
}</code></pre></div>

<h4>Solution: Use SET NULL</h4>
<div class="query-item"><pre><code class="language-php">#[ORM\Entity]
#[Gedmo\SoftDeleteable]
class <?php echo $e($shortClass); ?>

{
    #[ORM\JoinColumn(onDelete: 'SET NULL', nullable: true)]
    private ?Category $<?php echo $e($fieldName); ?>;
}</code></pre></div>

<p>Use SET NULL to orphan children, or ORM cascade to soft delete children too. Never mix soft delete with database CASCADE DELETE.</p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Fix CASCADE DELETE conflict with soft delete on %s::%s', $shortClass, $fieldName),
];
