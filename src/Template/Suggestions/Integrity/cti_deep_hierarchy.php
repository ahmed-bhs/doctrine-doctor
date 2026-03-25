<?php

declare(strict_types=1);

/**
 * @var string $entity_class
 * @var string $root_class
 * @var int $depth
 * @var int $joins_required
 * @var list<string> $chain
 */

/** @var array<string, mixed> $context */
$entityClass = (string) ($context['entity_class'] ?? 'Entity');
$rootClass = (string) ($context['root_class'] ?? 'RootEntity');
$depth = (int) ($context['depth'] ?? 0);
$joinsRequired = (int) ($context['joins_required'] ?? 0);
$chain = (array) ($context['chain'] ?? []);

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Deep CTI Hierarchy: <?php echo $e($entityClass); ?> (<?php echo $depth; ?> levels)</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-<?php echo $depth >= 4 ? 'danger' : 'warning'; ?>">
        Entity <strong><?php echo $e($entityClass); ?></strong> is <?php echo $depth; ?> levels deep in a Class Table Inheritance hierarchy.
        Every query for this entity requires <strong><?php echo $joinsRequired; ?> JOIN(s)</strong>.
    </div>

    <?php if ([] !== $chain): ?>
    <h4>Inheritance chain</h4>
    <div class="query-item">
        <pre><code><?php echo $e(implode(' -> ', $chain)); ?></code></pre>
    </div>
    <?php endif; ?>

    <h4>Why this matters</h4>
    <p>Each level in a JOINED hierarchy adds a mandatory JOIN to every SELECT, UPDATE, and DELETE.
    With <?php echo $joinsRequired; ?> JOINs, query plans become complex and index usage deteriorates.</p>

    <h4>Options</h4>
    <ul>
        <li><strong>Flatten</strong>: Merge intermediate classes that add few fields into their parent or child</li>
        <li><strong>Switch to STI</strong>: If most subclasses share similar fields, Single Table Inheritance avoids all JOINs</li>
        <li><strong>Use composition</strong>: Replace deep inheritance with embeddables or associations</li>
    </ul>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/inheritance-mapping.html" target="_blank" class="doc-link">
            Doctrine: Inheritance Mapping
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'CTI entity %s is %d levels deep — every query requires %d JOIN(s), consider flattening or switching to STI',
        $entityClass,
        $depth,
        $joinsRequired,
    ),
];
