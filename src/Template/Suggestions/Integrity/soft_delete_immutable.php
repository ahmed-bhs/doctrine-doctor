<?php

declare(strict_types=1);

$entityClass = $context['entity_class'] ?? '';
$entityClass = is_string($entityClass) ? $entityClass : '';
$fieldName = $context['field_name'] ?? '';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$shortClass = basename(str_replace('\\', '/', $entityClass));

ob_start();
?>
<?php echo suggestionHeader('Use DateTimeImmutable for soft delete'); ?>
<div class="suggestion-content">
<p><?php echo t('<code>%s::$%s</code> holds a mutable <code>DateTime</code>. Code that receives it can change the deletion date in place, and Doctrine will not notice the change.', $e($shortClass), $e($fieldName)); ?></p>

<div class="query-item"><pre><code class="language-php">#[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
private ?\DateTimeImmutable $<?php echo $e($fieldName); ?> = null;

public function delete(): void
{
    $this-&gt;<?php echo $e($fieldName); ?> = new \DateTimeImmutable();
}</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/basic-mapping.html#doctrine-mapping-types', 'Doctrine ORM Mapping Types'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Use DateTimeImmutable for soft delete field %s::%s', $shortClass, $fieldName),
];
