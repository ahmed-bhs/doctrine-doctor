<?php

declare(strict_types=1);

/**
 * Template for Missing Embeddable Opportunity.
 */
['entity_class' => $entityClass, 'fields' => $fields] = $context;
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>💡 Consider Extracting Embeddable</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-info">
        Entity <code><?= $e($entityClass) ?></code> has related fields that could be an Embeddable:<br>
        <code><?= implode(', ', array_map($e, $fields)) ?></code>
    </div>

    <h4>Refactoring to Embeddable</h4>
    <div class="query-item">
        <pre><code class="language-php">// Before: Scattered fields
class <?= $e($entityClass) ?> {
<?php assert(is_iterable($fields), '$fields must be iterable');
foreach ($fields as $field): ?>
    private string $<?= $e($field) ?>;
<?php endforeach; ?>
}

// After: Grouped as Value Object
#[ORM\Embeddable]
readonly class Address {
    public function __construct(
<?php assert(is_iterable($fields), '$fields must be iterable');
foreach ($fields as $i => $field): ?>
        private string $<?= $e($field) ?><?= $i < count($fields) - 1 ? ',' : '' ?>

<?php endforeach; ?>
    ) {}
}

class <?= $e($entityClass) ?> {
    #[ORM\Embedded(class: Address::class)]
    private Address $address;
}</code></pre>
    </div>

    <p><strong>Benefits:</strong> Better encapsulation, reusability, validation in one place.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Extract related fields into an Embeddable Value Object',
];
