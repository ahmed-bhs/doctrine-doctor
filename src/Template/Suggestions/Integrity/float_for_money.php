<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $entityClass
 * @var mixed $fieldName
 * @var array<string, mixed> $context
 */
$entityClass = (string) ($context['entity_class'] ?? 'Product');
$fieldName = (string) ($context['field_name'] ?? 'price');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Store money as an integer amount'); ?>
<div class="suggestion-content">
<p><?php echo t('<code>%s::$%s</code> stores money as a float, which cannot represent most decimal amounts exactly (<code>0.1 + 0.2 !== 0.3</code>). Rounding errors then show up in totals and comparisons.', $e($entityClass), $e($fieldName)); ?></p>

<div class="query-item"><pre><code class="language-php">use Money\{Currency, Money};

// Before: #[ORM\Column(type: 'float')] private float $<?php echo $e($fieldName); ?>;
#[ORM\Column(type: 'integer')]
private int $<?php echo $e($fieldName); ?>Cents;

public function get<?php echo $e(ucfirst($fieldName)); ?>(): Money
{
    return new Money($this-><?php echo $e($fieldName); ?>Cents, new Currency('EUR'));
}</code></pre></div>

<p><?php echo t('A <code>decimal</code> column mapped to a PHP <code>string</code> also keeps exact values.'); ?></p>

<?php echo suggestionDocLink('https://github.com/moneyphp/money', 'Money PHP library'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Replace float with an integer amount or a decimal column'),
];
