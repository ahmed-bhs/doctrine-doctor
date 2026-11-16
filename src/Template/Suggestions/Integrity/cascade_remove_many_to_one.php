<?php

declare(strict_types=1);

/**
 * Template for CascadeRemoveOnIndependentEntityAnalyzer - ManyToOne
 */
$entityClass = (string) ($context['entity_class'] ?? 'Order');
$fieldName = (string) ($context['field_name'] ?? 'customer');
$targetEntity = (string) ($context['target_entity'] ?? 'Customer');

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

$lastBackslashClass = strrchr($entityClass, '\\');
$shortClass = false !== $lastBackslashClass ? substr($lastBackslashClass, 1) : $entityClass;

$lastBackslashTarget = strrchr((string) $targetEntity, '\\');
$shortTarget = false !== $lastBackslashTarget ? substr($lastBackslashTarget, 1) : (string) $targetEntity;

ob_start();
?>
<?php echo suggestionHeader('Remove cascade remove from the ManyToOne'); ?>
<div class="suggestion-content">
<p><code><?= $e($shortClass) ?>::$<?= $e($fieldName) ?></code> uses <code>cascade: ['remove']</code> on a ManyToOne association. Deleting a <?= $e($shortClass) ?> also deletes its <?= $e($shortTarget) ?>, which other rows may still reference.</p>

<div class="query-item"><pre><code class="language-php">// Before
#[ORM\ManyToOne(targetEntity: <?= $e($shortTarget) ?>::class, cascade: ['remove'])]
private ?<?= $e($shortTarget) ?> $<?= $e($fieldName) ?>;

// After
#[ORM\ManyToOne(targetEntity: <?= $e($shortTarget) ?>::class)]
private ?<?= $e($shortTarget) ?> $<?= $e($fieldName) ?>;</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/working-with-associations.html#transitive-persistence-cascade-operations', 'Doctrine ORM Cascade Operations'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Remove cascade="remove" from ManyToOne relation',
];
