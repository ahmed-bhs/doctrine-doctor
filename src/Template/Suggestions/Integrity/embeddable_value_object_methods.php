<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $embeddableClass
 * @var mixed $missingMethods
 * @var mixed $context
 */
['embeddable_class' => $embeddableClass, 'missing_methods' => $missingMethods] = $context;
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Incomplete Value Object</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-info">
        <strong>Embeddable:</strong> <code><?php echo $e($embeddableClass); ?></code><br>
        <strong>Missing:</strong> <?php echo implode(', ', array_map($e, $missingMethods)); ?>
    </div>

    <h4>Recommended Implementation</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Embeddable]
readonly class <?php echo $e($embeddableClass); ?> {
    public function __construct(
        private int $amount,
        private string $currency
    ) {
        // Validate in constructor
        if ($amount < 0) {
            throw new \InvalidArgumentException('Amount cannot be negative');
        }
    }

    // Equality check
    public function equals(self $other): bool {
        return $this->amount === $other->amount
            && $this->currency === $other->currency;
    }

    // Immutable operations
    public function add(self $other): self {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Currency mismatch');
        }
        return new self($this->amount + $other->amount, $this->currency);
    }

    // String representation
    public function __toString(): string {
        return sprintf('%.2f %s', $this->amount / 100, $this->currency);
    }
}</code></pre>
    </div>

    <p><strong>Value Object best practices:</strong> immutability (readonly), validation in constructor, equals(), domain methods.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Add Value Object methods (equals, validation, domain logic)',
];
