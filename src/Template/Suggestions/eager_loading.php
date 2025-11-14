<?php

declare(strict_types=1);

/**
 * Template for Eager Loading (N+1 Query) suggestions.
 * Context variables:
 * @var string $entity - Entity name
 * @var string $relation - Relation name
 * @var int    $query_count - Number of queries detected
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
['entity' => $entity, 'relation' => $relation, 'query_count' => $queryCount] = $context;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

// Start output buffering for clean code block
ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
        <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/>
    </svg>
    <h4>N+1 query problem</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>N+1 query problem:</strong> <?php echo $queryCount; ?> queries loading <code><?php echo $e($relation); ?></code> relation.
    </div>

    <h4>Solution: Eager load with JOIN</h4>
    <div class="query-item">
        <pre><code class="language-php">$entities = $repository->createQueryBuilder('e')
    ->leftJoin('e.<?php echo $e($relation); ?>', 'r')
    ->addSelect('r')
    ->getQuery()
    ->getResult();

foreach ($entities as $entity) {
    $entity->get<?php echo ucfirst($relation); ?>(); // Already loaded
}
// Result: 1 query instead of <?php echo $queryCount; ?></code></pre>
    </div>

    <p>Avoid <code>fetch: 'EAGER'</code> globally as it loads data you might not need.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#joins" target="_blank" class="doc-link">
            📖 Doctrine DQL joins →
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
