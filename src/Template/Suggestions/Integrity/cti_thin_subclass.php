<?php

declare(strict_types=1);

/**
 * @var string $root_class
 * @var string $subclass
 * @var list<string> $own_fields
 * @var int $own_field_count
 * @var int $parent_field_count
 */

/** @var array<string, mixed> $context */
$rootClass = (string) ($context['root_class'] ?? 'RootEntity');
$subclass = (string) ($context['subclass'] ?? 'SubEntity');
$ownFields = (array) ($context['own_fields'] ?? []);
$ownFieldCount = (int) ($context['own_field_count'] ?? 0);
$parentFieldCount = (int) ($context['parent_field_count'] ?? 0);

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Thin CTI Subclass: <?php echo $e($subclass); ?></h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-info">
        Subclass <strong><?php echo $e($subclass); ?></strong> adds only <strong><?php echo $ownFieldCount; ?></strong> field(s) to <strong><?php echo $e($rootClass); ?></strong> (which has <?php echo $parentFieldCount; ?> fields).
        Every polymorphic query pays a JOIN cost for very little additional data.
    </div>

    <?php if ([] !== $ownFields): ?>
    <h4>Fields added by <?php echo $e($subclass); ?></h4>
    <ul>
        <?php foreach ($ownFields as $field): ?>
        <li><code><?php echo $e((string) $field); ?></code></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <h4>Consider: Switch to Single Table Inheritance (SINGLE_TABLE)</h4>
    <p>When subclasses add minimal fields, STI avoids JOIN overhead at the cost of a few nullable columns:</p>
    <div class="query-item">
        <pre><code class="language-php">use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
class <?php echo $e($rootClass); ?>

{
    // All fields in one table — no JOINs needed
}

class <?php echo $e($subclass); ?> extends <?php echo $e($rootClass); ?>

{
    // <?php echo $ownFieldCount; ?> extra nullable column(s) instead of a separate table
}</code></pre>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/inheritance-mapping.html#single-table-inheritance" target="_blank" class="doc-link">
            Doctrine: Single Table Inheritance
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'CTI subclass %s adds only %d field(s) — a JOIN for %d column(s) is disproportionate, consider switching to SINGLE_TABLE',
        $subclass,
        $ownFieldCount,
        $ownFieldCount,
    ),
];
