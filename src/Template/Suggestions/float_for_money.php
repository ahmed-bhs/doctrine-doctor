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

    <p>Floating point can't represent decimals precisely (0.1 + 0.2 = 0.30000000000000004). This causes financial losses.</p>

    <h4>Current</h4>
    <pre><code class="language-php">#[ORM\Column(type: 'float')]
public float $<?php echo $e($fieldName); ?>;</code></pre>

    <h4>Solution: Money library (recommended)</h4>
    <pre><code class="language-php">use Money\Money;
use Money\Currency;

#[ORM\Column(type: 'integer')] // Store cents
private int $<?php echo $e($fieldName); ?>Cents;

public function get<?php echo ucfirst((string) $fieldName); ?>(): Money
{
    return new Money($this-><?php echo $e($fieldName); ?>Cents, new Currency('EUR'));
}

// Usage:
$product->set<?php echo ucfirst((string) $fieldName); ?>(Money::EUR(1999)); // 19.99 EUR</code></pre>

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
