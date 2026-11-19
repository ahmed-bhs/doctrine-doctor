<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $entityClass
 * @var mixed $fieldName
 * @var array<string, mixed> $context
 */
$entityClass = (string) ($context['entity_class'] ?? 'Entity');
$fieldName   = (string) ($context['field_name'] ?? 'created_at');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Remove the timestamp setter'); ?>

<div class="suggestion-content">
    <p><?php echo t('<code>%s::$%s</code> has a public setter, so any caller can overwrite a value that Timestampable is meant to set. Keep only the getter.', $e($entityClass), $e($fieldName)); ?></p>

    <div class="query-item">
        <pre><code class="language-php">#[ORM\Column]
#[Gedmo\Timestampable(on: 'create')]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

public function get<?php echo $e(ucfirst((string) $fieldName)); ?>(): \DateTimeImmutable
{
    return $this-&gt;<?php echo $e($fieldName); ?>;
}</code></pre>
    </div>

    <?php echo suggestionDocLink('https://github.com/doctrine-extensions/DoctrineExtensions/blob/main/doc/timestampable.md', 'Doctrine Extensions Timestampable'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Remove the public setter of %s::$%s', $entityClass, $fieldName),
];
