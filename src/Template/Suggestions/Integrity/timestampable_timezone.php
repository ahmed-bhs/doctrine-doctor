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
<?php echo suggestionHeader('Store timestamps in UTC'); ?>

<div class="suggestion-content">
    <p><?php echo t('<code>%s::$%s</code> uses <code>datetime</code>, which drops the timezone. Values written from different timezones become ambiguous unless they are all converted to UTC first.', $e($entityClass), $e($fieldName)); ?></p>

    <div class="query-item">
        <pre><code class="language-php">#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

#[ORM\PrePersist]
public function onCreate(): void
{
    $this-&gt;<?php echo $e($fieldName); ?> = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
}</code></pre>
    </div>

    <p><?php echo t('To keep the original offset instead, map the field as <code>datetimetz_immutable</code>.'); ?></p>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#datetimetz', 'Doctrine DateTimeTZ'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Store %s::$%s in UTC or map it as datetimetz', $entityClass, $fieldName),
];
