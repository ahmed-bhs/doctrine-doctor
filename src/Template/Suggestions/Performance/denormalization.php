<?php

declare(strict_types=1);

/**
 * Template for Denormalization (Counter Field) suggestions.
 * @var array<string, mixed> $context
 */
$entity = (string) ($context['entity'] ?? 'Entity');
$relation = (string) ($context['relation'] ?? 'items');
$queryCount = max(0, (int) ($context['query_count'] ?? 0));
$counterField = (string) ($context['counter_field'] ?? lcfirst($relation) . 'Count');

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$itemClass = ucfirst(rtrim($relation, 's'));

ob_start();
?>
<?php echo suggestionHeader('Store the count in a counter column'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <?php echo $queryCount; ?> COUNT queries ran on the <code><?php echo $e($relation); ?></code> collection, one per <code><?php echo $e($entity); ?></code>.
    A <code><?php echo $e($counterField); ?></code> column kept up to date when items are added or removed is read with the entity, without a query.
</div>

<div class="query-item"><pre><code class="language-php">#[ORM\Column]
private int $<?php echo $e($counterField); ?> = 0;
public function add<?php echo $e($itemClass); ?>(<?php echo $e($itemClass); ?> $item): void
{
    if (!$this-&gt;<?php echo $e($relation); ?>-&gt;contains($item)) {
        $this-&gt;<?php echo $e($relation); ?>-&gt;add($item);
        ++$this-&gt;<?php echo $e($counterField); ?>;
    }
}

public function remove<?php echo $e($itemClass); ?>(<?php echo $e($itemClass); ?> $item): void
{
    if ($this-&gt;<?php echo $e($relation); ?>-&gt;removeElement($item)) {
        --$this-&gt;<?php echo $e($counterField); ?>;
    }
}</code></pre></div>

<p>Fill existing rows once in the migration with <code>UPDATE ... SET <?php echo $e($counterField); ?> = (SELECT COUNT(*) ...)</code>; writes that bypass these methods must update the column too.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/cookbook/aggregate-fields.html', 'Doctrine Aggregate Fields Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Store the %s count in a %s column instead of counting it per entity', $relation, $counterField),
];
