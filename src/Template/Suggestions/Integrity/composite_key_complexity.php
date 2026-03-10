<?php

declare(strict_types=1);

/**
 * Template for CompositeKeyComplexityAnalyzer suggestions.
 * Context variables:
 * @var string $entity_name - Full entity class name
 * @var string $short_name - Short entity class name
 * @var array $identifier_fields - List of identifier field names
 * @var int $column_count - Number of columns in composite key
 * @var array $referenced_by - Entities referencing this composite key
 */

/** @var array<string, mixed> $context */
$entityName = (string) ($context['entity_name'] ?? 'App\\Entity\\Example');
$shortName = (string) ($context['short_name'] ?? 'Example');
$identifierFields = (array) ($context['identifier_fields'] ?? ['id']);
$columnCount = (int) ($context['column_count'] ?? 2);
$referencedBy = (array) ($context['referenced_by'] ?? []);

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Composite Primary Key in <?php echo $e($shortName); ?></h4>
</div>

<div class="suggestion-content">
<?php if ($columnCount >= 3): ?>
    <div class="alert alert-danger">
        <strong><?php echo $e($shortName); ?></strong> has a composite primary key with <strong><?php echo $columnCount; ?> columns</strong> (<code><?php echo $e(implode(', ', $identifierFields)); ?></code>). This severely limits Doctrine ORM features.
    </div>
<?php else: ?>
    <div class="alert alert-warning">
        <strong><?php echo $e($shortName); ?></strong> has a composite primary key (<code><?php echo $e(implode(', ', $identifierFields)); ?></code>). This limits Doctrine ORM features.
    </div>
<?php endif; ?>

    <h4>Limitations</h4>
    <ul>
        <li><code>getReference()</code> does not work with composite keys</li>
        <li>Entities referencing <strong><?php echo $e($shortName); ?></strong> must map all <?php echo $columnCount; ?> key columns in their foreign keys</li>
        <li>Identity map lookups are slower (hash of <?php echo $columnCount; ?> values instead of 1)</li>
        <li><code>GeneratedValue</code> strategy is unavailable</li>
        <li><code>$repository->find($id)</code> requires an array instead of a scalar</li>
    </ul>

<?php if ([] !== $referencedBy): ?>
    <h4>Impacted entities</h4>
    <p>The following entities reference <strong><?php echo $e($shortName); ?></strong> and must carry the full composite foreign key:</p>
    <ul>
<?php foreach ($referencedBy as $ref): ?>
        <li><code><?php echo $e((string) $ref); ?></code></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>

    <h4>Solution: Add a surrogate primary key</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Entity]
#[ORM\UniqueConstraint(columns: [<?php echo implode(', ', array_map(fn ($f) => "'" . $e($f) . "'", $identifierFields)); ?>])]
class <?php echo $e($shortName); ?>

{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

<?php foreach ($identifierFields as $field): ?>
    // '<?php echo $e($field); ?>' is no longer part of the primary key
<?php endforeach; ?>
}

// Then generate a migration to update the schema</code></pre>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/tutorials/composite-primary-keys.html" target="_blank" class="doc-link">
            📜 Doctrine Composite Keys
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

$severity = $columnCount >= 3 ? 'critical' : 'warning';

return [
    'code'        => $code,
    'description' => sprintf(
        'Replace composite key (%s) with a surrogate primary key in %s',
        implode(', ', $identifierFields),
        $shortName,
    ),
];
