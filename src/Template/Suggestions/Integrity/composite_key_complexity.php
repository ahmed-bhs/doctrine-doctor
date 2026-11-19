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
<?php echo suggestionHeader('Add a surrogate primary key'); ?>
<div class="suggestion-content">
<p><?php echo [] !== $referencedBy
    ? t('<code>%s</code> has a %s-column primary key (<code>%s</code>), and %s must repeat all of it in their foreign keys. Composite keys also rule out <code>#[ORM\\GeneratedValue]</code> and need an array in <code>find()</code>.', $e($shortName), $columnCount, $e(implode(', ', $identifierFields)), $e(implode(', ', array_map(strval(...), $referencedBy))))
    : t('<code>%s</code> has a %s-column primary key (<code>%s</code>). Composite keys also rule out <code>#[ORM\\GeneratedValue]</code> and need an array in <code>find()</code>.', $e($shortName), $columnCount, $e(implode(', ', $identifierFields))); ?></p>

<div class="query-item"><pre><code class="language-php">#[ORM\Entity]
#[ORM\UniqueConstraint(columns: [<?php echo implode(', ', array_map(fn ($f) => "'" . $e((string) $f) . "'", $identifierFields)); ?>])]
class <?php echo $e($shortName); ?>

{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Former key columns stay as regular columns, unique together
}</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/tutorials/composite-primary-keys.html', 'Doctrine Composite Keys'); ?>
</div>

<?php
$code = ob_get_clean();

$severity = $columnCount >= 3 ? 'critical' : 'warning';

return [
    'code'        => $code,
    'description' => t(
        'Replace composite key (%s) with a surrogate primary key in %s',
        implode(', ', $identifierFields),
        $shortName,
    ),
];
