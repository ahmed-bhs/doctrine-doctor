<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $embeddableClass
 * @var mixed $context
 */
['embeddable_class' => $embeddableClass] = $context;
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Embeddable Should Be Immutable</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-info">
        Embeddable <code><?= $e($embeddableClass) ?></code> has public setters - Value Objects should be immutable.
    </div>

    <h4>Solution: Make it readonly</h4>
    <div class="query-item">
        <pre><code class="language-php">// Before: Mutable embeddable
#[ORM\Embeddable]
class <?= $e($embeddableClass) ?> {
    private int $amount;
    private string $currency;

    public function setAmount(int $amount): void {
        $this->amount = $amount;  // Mutable!
    }
}

// After: Immutable Value Object
#[ORM\Embeddable]
readonly class <?= $e($embeddableClass) ?> {
    public function __construct(
        private int $amount,
        private string $currency
    ) {}

    // Only getters, no setters
    public function getAmount(): int { return $this->amount; }

    // Return new instance for changes
    public function withAmount(int $amount): self {
        return new self($amount, $this->currency);
    }
}</code></pre>
    </div>

    <p><strong>Best practice:</strong> Value Objects should be immutable. Use <code>readonly</code> and constructor injection.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Make embeddable immutable using readonly',
];
