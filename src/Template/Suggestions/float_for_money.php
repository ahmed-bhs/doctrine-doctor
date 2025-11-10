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
    <h4>CRITICAL: Float Used for Money</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        🚨 <strong>CRITICAL ISSUE</strong><br>
        Entity <code><?php echo $e($entityClass); ?>::$<?php echo $e($fieldName); ?></code> uses float/double for monetary values.<br>
        This can cause <strong>financial discrepancies</strong>!
    </div>

    <h4>The Problem</h4>
    <div class="query-item">
        <pre><code class="language-php">// PROBLEM: Float for money
#[ORM\Column(type: 'float')]
public float $<?php echo $e($fieldName); ?>;

// Example of problem:
$product-><?php echo $e($fieldName); ?> = 0.1 + 0.2;
// Result: 0.30000000000000004 (!)
// In finance: DISASTER</code></pre>
    </div>

    <h4> Solution 1: Decimal Type (Basic)</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
public string $<?php echo $e($fieldName); ?>;

// Calculations with bcmath
public function calculateTotal(int $quantity): string
{
    return bcmul($this-><?php echo $e($fieldName); ?>, (string)$quantity, 2);
}</code></pre>
    </div>

    <h4> Solution 2: Money Library (RECOMMENDED)</h4>
    <div class="query-item">
        <pre><code class="language-php">use Money\Money;
use Money\Currency;

#[ORM\Column(type: 'integer')] // Stores in cents
private int $<?php echo $e($fieldName); ?>Cents;

public function get<?php echo ucfirst((string) $fieldName); ?>(): Money
{
    return new Money($this-><?php echo $e($fieldName); ?>Cents, new Currency('EUR'));
}

// Usage:
$product->set<?php echo ucfirst((string) $fieldName); ?>(Money::EUR(1999)); // 19.99 EUR
$total = $product->get<?php echo ucfirst((string) $fieldName); ?>()->multiply($quantity);</code></pre>
    </div>

    <h4>Real-World Impact</h4>
    <ul>
        <li>Account balances become incorrect over time</li>
        <li>Totals don't match sum of parts</li>
        <li>Rounding errors accumulate</li>
        <li>Auditing/accounting issues</li>
        <li>Legal/compliance problems</li>
        <li>Customer trust issues</li>
    </ul>

    <div class="alert alert-danger">
        <strong>CRITICAL:</strong> Floating point errors can cause real financial losses!<br>
        Example: 0.1 + 0.2 = 0.30000000000000004
    </div>

    <p>
        <a href="https://github.com/moneyphp/money" target="_blank" class="doc-link">
            📖 Money PHP Library →
        </a>
        <a href="https://0.30000000000000004.com/" target="_blank" class="doc-link">
            📖 Why 0.1 + 0.2 ≠ 0.3 →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Replace float/double with decimal or Money library for monetary values',
];
