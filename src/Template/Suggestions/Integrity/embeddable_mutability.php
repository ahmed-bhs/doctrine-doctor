<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $embeddableClass
 * @var array<string, mixed> $context
 */
$embeddableClass = (string) ($context['embeddable_class'] ?? 'Money');
$severity = (string) ($context['severity'] ?? 'info');
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Make the embeddable immutable'); ?>
<div class="suggestion-content">
<div class="alert <?php echo severityAlertClass($severity); ?>"><?php echo t('Embeddable <code>%s</code> has public setters. A value object changed in place also changes every entity holding that instance, so return a new instance instead.', $e($embeddableClass)); ?></div>

<div class="query-item"><pre><code class="language-php">#[ORM\Embeddable]
final readonly class <?= $e($embeddableClass) ?>

{
    public function __construct(
        #[ORM\Column] private int $amount,
        #[ORM\Column(length: 3)] private string $currency,
    ) {}

    // Before: public function setAmount(int $amount): void
    public function withAmount(int $amount): self
    {
        return new self($amount, $this->currency);
    }
}</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/tutorials/embeddables.html', 'Doctrine ORM Embeddables'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Make the embeddable immutable with readonly properties'),
];
