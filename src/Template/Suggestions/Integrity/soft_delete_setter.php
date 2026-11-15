<?php

declare(strict_types=1);

$entityClass = $context['entity_class'] ?? '';
$entityClass = is_string($entityClass) ? $entityClass : '';
$fieldName = $context['field_name'] ?? '';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$shortClass = basename(str_replace('\\', '/', $entityClass));

ob_start();
?>
<?php echo suggestionHeader('Replace the soft delete setter'); ?>
<div class="suggestion-content">
<p><code><?php echo $e($shortClass); ?>::$<?php echo $e($fieldName); ?></code> has a public setter, so any caller can backdate or clear a deletion. Expose <code>delete()</code> and <code>restore()</code> instead.</p>

<div class="query-item"><pre><code class="language-php">public function delete(): void
{
    $this-&gt;<?php echo $e($fieldName); ?> ??= new \DateTimeImmutable();
}

public function restore(): void
{
    $this-&gt;<?php echo $e($fieldName); ?> = null;
}

public function get<?php echo $e(ucfirst($fieldName)); ?>(): ?\DateTimeImmutable
{
    return $this-&gt;<?php echo $e($fieldName); ?>;
}</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/basic-mapping.html#doctrine-mapping-types', 'Doctrine ORM Mapping Types'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Remove public setter on soft delete field %s::%s', $shortClass, $fieldName),
];
