<?php

declare(strict_types=1);

/**
 * Template for Float Used for Money.
 * Context variables:
 */
['entity_class' => $entityClass, 'field_name' => $fieldName] = $context;
$e                                                           = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Float used for money</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>Critical issue</strong> - <code><?php echo $e($entityClass); ?>::$<?php echo $e($fieldName); ?></code> uses float/double for monetary values. This will cause financial discrepancies.
    </div>

    <p>Floating point numbers can't represent decimal values precisely. For example, <code>0.1 + 0.2 = 0.30000000000000004</code>. Over multiple transactions, these rounding errors accumulate and cause real financial losses.</p>

    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Column(type: 'float')]
public float $<?php echo $e($fieldName); ?>;

// Problem:
$product-><?php echo $e($fieldName); ?> = 0.1 + 0.2;
// Result: 0.30000000000000004</code></pre>
    </div>

    <h4>Option 1: Decimal type</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
public string $<?php echo $e($fieldName); ?>;

// Calculations with bcmath
public function calculateTotal(int $quantity): string
{
    return bcmul($this-><?php echo $e($fieldName); ?>, (string)$quantity, 2);
}</code></pre>
    </div>

    <h4>Option 2: Money library (recommended)</h4>
    <div class="query-item">
        <pre><code class="language-php">use Money\Money;
use Money\Currency;

#[ORM\Column(type: 'integer')] // Store cents
private int $<?php echo $e($fieldName); ?>Cents;

public function get<?php echo ucfirst((string) $fieldName); ?>(): Money
{
    return new Money($this-><?php echo $e($fieldName); ?>Cents, new Currency('EUR'));
}

// Usage:
$product->set<?php echo ucfirst((string) $fieldName); ?>(Money::EUR(1999)); // 19.99 EUR
$total = $product->get<?php echo ucfirst((string) $fieldName); ?>()->multiply($quantity);</code></pre>
    </div>

    <p>Use the Money library for better handling of currencies, rounding, and formatting.</p>

    <p>
        <a href="https://github.com/moneyphp/money" target="_blank" class="doc-link">
            📖 Money PHP library
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Replace float with decimal or Money library for monetary values',
];
