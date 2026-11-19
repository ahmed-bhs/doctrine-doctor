<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $embeddableClass
 * @var mixed $missingMethods
 * @var array<string, mixed> $context
 */
$embeddableClass = (string) ($context['embeddable_class'] ?? 'Money');
$missingMethods = $context['missing_methods'] ?? ['equals', '__toString'];
if (!is_array($missingMethods) || [] === $missingMethods) {
    $missingMethods = ['equals', '__toString'];
}
$missingMethods = array_values(array_map(static fn (mixed $method): string => (string) $method, $missingMethods));
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Add value object methods'); ?>
<div class="suggestion-content">
<p><?php echo t('Embeddable <code>%s</code> lacks %s. Without them, callers compare and print its fields by hand.', $e($embeddableClass), implode(', ', array_map(static fn (mixed $method): string => '<code>' . $e((string) $method) . '</code>', $missingMethods))); ?></p>

<div class="query-item"><pre><code class="language-php">#[ORM\Embeddable]
final readonly class <?php echo $e($embeddableClass); ?>

{
    // ... constructor and mapped columns

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    public function __toString(): string
    {
        return sprintf('%d %s', $this->amount, $this->currency);
    }
}</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/tutorials/embeddables.html', 'Doctrine ORM Embeddables'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Add the missing value object methods'),
];
