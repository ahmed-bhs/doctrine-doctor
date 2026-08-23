<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $entity
 * @var mixed $relation
 * @var int $queryCount
 * @var array<string, mixed> $context
 */
$entity          = (string) ($context['entity'] ?? 'Entity');
$relation        = (string) ($context['relation'] ?? 'items');
$queryCount      = (int) ($context['query_count'] ?? 0);
$triggerLocation = (string) ($context['trigger_location'] ?? 'the calling code');
// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

// Start output buffering for clean code block
ob_start();
?>

<?php echo suggestionHeader('N+1 query problem'); ?>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <?php echo $queryCount; ?> queries loading <code><?php echo $e($relation); ?></code>
    </div>

<?php if ('' !== $triggerLocation) { ?>
    <div class="alert alert-info">
        <strong>Triggered at:</strong> <code><?php echo $e($triggerLocation); ?></code>
    </div>
<?php } ?>

    <h4>Eager load with JOIN</h4>
    <div class="query-item">
        <pre><code class="language-php">$entities = $repository->createQueryBuilder('e')
    ->leftJoin('e.<?php echo $e($relation); ?>', 'r')
    ->addSelect('r')
    ->getQuery()
    ->getResult();

foreach ($entities as $entity) {
    $entity->get<?php echo $e(ucfirst((string) $relation)); ?>(); // Already loaded
}
// 1 query instead of <?php echo $queryCount; ?></code></pre>
    </div>

    <p>Avoid <code>fetch: 'EAGER'</code> globally.</p>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#joins', 'Doctrine DQL joins'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'N+1 query detected on %s relation - use eager loading with JOIN FETCH',
        $relation,
    ),
];
