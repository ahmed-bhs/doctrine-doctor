<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $embeddableClass
 * @var mixed $fieldName
 * @var array<string, mixed> $context
 */
$embeddableClass = (string) ($context['embeddable_class'] ?? 'Money');
$fieldName       = (string) ($context['field_name'] ?? 'created_at');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Store the amount in minor units'); ?>
<div class="suggestion-content">
<p><?php echo t('Money embeddable <code>%s::$%s</code> uses a float, which cannot represent most decimal amounts exactly (<code>0.1 + 0.2 !== 0.3</code>). Store the smallest currency unit as an integer.', $e($embeddableClass), $e($fieldName)); ?></p>

<div class="query-item"><pre><code class="language-php">#[ORM\Embeddable]
final readonly class <?php echo $e($embeddableClass); ?>

{
    public function __construct(
        // Before: #[ORM\Column(type: 'float')] private float $<?php echo $e($fieldName); ?>,
        #[ORM\Column(type: 'integer')] private int $amountInCents,
        #[ORM\Column(length: 3)] private string $currency,
    ) {}
}</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/tutorials/embeddables.html', 'Doctrine ORM Embeddables'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Use integers (cents) instead of floats for monetary values'),
];
