<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entity
 * @var mixed $relation
 * @var mixed $queryCount
 * @var mixed $context
 */
['entity' => $entity, 'relation' => $relation, 'query_count' => $queryCount] = $context;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

// Start output buffering for clean code block
ob_start();
?>

<div class="suggestion-header">
    <h4>N+1 query problem</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <?php echo $queryCount; ?> queries loading <code><?php echo $e($relation); ?></code>
    </div>

    <h4>Eager load with JOIN</h4>
    <div class="query-item">
        <pre><code class="language-php">$entities = $repository->createQueryBuilder('e')
    ->leftJoin('e.<?php echo $e($relation); ?>', 'r')
    ->addSelect('r')
    ->getQuery()
    ->getResult();

foreach ($entities as $entity) {
    $entity->get<?php echo ucfirst($relation); ?>(); // Already loaded
}
// 1 query instead of <?php echo $queryCount; ?></code></pre>
    </div>

    <p>Avoid <code>fetch: 'EAGER'</code> globally.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#joins" target="_blank" class="doc-link">
            📖 Doctrine DQL joins
        </a>
    </p>
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
