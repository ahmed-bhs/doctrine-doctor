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

<?php echo suggestionHeader('Embeddable Should Be Immutable'); ?>

<div class="suggestion-content">
    <div class="alert <?php echo severityAlertClass($severity); ?>">
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

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/tutorials/embeddables.html', 'Doctrine ORM Embeddables'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Make the embeddable immutable with readonly properties',
];
