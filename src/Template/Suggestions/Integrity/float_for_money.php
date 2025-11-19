<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entityClass
 * @var mixed $fieldName
 * @var mixed $context
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
        <code><?php echo $e($entityClass); ?>::$<?php echo $e($fieldName); ?></code> uses float for monetary values, which causes precision errors.
    </div>

    <p>Floating point arithmetic isn't exact (0.1 + 0.2 ≠ 0.3).</p>

    <h4>Current</h4>
    <pre><code class="language-php">#[ORM\Column(type: 'float')]
public float $<?php echo $e($fieldName); ?>;</code></pre>

    <h4>Solution: Money library</h4>
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
