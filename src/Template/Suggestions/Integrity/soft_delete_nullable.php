<?php

declare(strict_types=1);

$entityClass = $context['entity_class'] ?? '';
$entityClass = is_string($entityClass) ? $entityClass : '';
$fieldName = $context['field_name'] ?? '';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$shortClass = basename(str_replace('\\', '/', $entityClass));

ob_start();
?>
<?php echo suggestionHeader('Make the soft delete field nullable'); ?>
<div class="suggestion-content">
<p><code><?php echo $e($shortClass); ?>::$<?php echo $e($fieldName); ?></code> is NOT NULL. Soft delete treats NULL as active and a date as deleted, so every row needs a date and counts as deleted.</p>

<div class="query-item"><pre><code class="language-php">#[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
private ?\DateTimeImmutable $<?php echo $e($fieldName); ?> = null;

public function isDeleted(): bool
{
    return null !== $this-&gt;<?php echo $e($fieldName); ?>;
}</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/basic-mapping.html#doctrine-mapping-types', 'Doctrine ORM Mapping Types'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Make soft delete field %s::%s nullable', $shortClass, $fieldName),
];
