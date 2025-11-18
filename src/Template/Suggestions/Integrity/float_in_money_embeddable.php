<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $embeddableClass
 * @var mixed $fieldName
 * @var mixed $context
 */
['embeddable_class' => $embeddableClass, 'field_name' => $fieldName] = $context;
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Float Used in Money Embeddable</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        Money embeddable <code><?php echo $e($embeddableClass); ?>::$<?php echo $e($fieldName); ?></code> uses float, which causes rounding errors in financial calculations (0.1 + 0.2 ≠ 0.3).
    </div>

    <h4>Solution: Use Integer (Cents)</h4>
    <div class="query-item">
        <pre><code class="language-php">// Before:
#[ORM\Embeddable]
class <?php echo $e($embeddableClass); ?> {
    #[ORM\Column(type: 'float')]
    private float $<?php echo $e($fieldName); ?>;
}

// After:
#[ORM\Embeddable]
readonly class <?php echo $e($embeddableClass); ?> {
    #[ORM\Column(type: 'integer')]
    private int $amountInCents;
    
    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;
    
    public static function fromDecimal(float $amount, string $currency): self {
        return new self((int)round($amount * 100), $currency);
    }
    
    public function toDecimal(): float {
        return $this->amountInCents / 100;
    }
}</code></pre>
    </div>

    <p>Store monetary values as integers (smallest unit: cents, pence, etc.) to avoid float precision issues.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use integer (cents) instead of float for money values',
];
