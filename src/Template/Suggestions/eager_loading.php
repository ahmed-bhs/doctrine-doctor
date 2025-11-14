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
        <strong>Found <?php echo $queryCount; ?> queries</strong> loading the <code><?php echo $e($relation); ?></code> relation. This is the classic N+1 problem.
    </div>

    <p>When you access a lazy-loaded relation inside a loop, Doctrine fires off a separate query for each item. So if you loop through 100 entities, you'll end up with 101 queries (1 for the entities + 100 for the relation).</p>

    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php">$entities = $repository->findAll();

foreach ($entities as $entity) {
    $entity->get<?php echo ucfirst($relation); ?>(); // Triggers a query each time
}
// Total: <?php echo $queryCount; ?> queries</code></pre>
    </div>

    <h4>Fix with eager loading</h4>
    <div class="query-item">
        <pre><code class="language-php">// Load everything in one go
$entities = $entityManager
    ->createQuery('
        SELECT e, r
        FROM App\\Entity\\<?php echo $e($entity); ?> e
        JOIN e.<?php echo $e($relation); ?> r
    ')
    ->getResult();

foreach ($entities as $entity) {
    $entity->get<?php echo ucfirst($relation); ?>(); // Already loaded, no query
}
// Total: 1 query</code></pre>
    </div>

    <h4>Other options</h4>

    <h5>Using a repository method</h5>
    <div class="query-item">
        <pre><code class="language-php">// In your repository
/**
 * @return array<mixed>
 */
public function findAllWithRelation(): array
{
    return $this->createQueryBuilder('e')
        ->leftJoin('e.<?php echo $e($relation); ?>', 'r')
        ->addSelect('r')
        ->getQuery()
        ->getResult();
}</code></pre>
    </div>

    <h5>Setting fetch mode in the entity</h5>
    <div class="query-item">
        <pre><code class="language-php">// In your entity (use sparingly)
#[ORM\ManyToOne(fetch: 'EAGER')]
private ?RelatedEntity $<?php echo $e($relation); ?> = null;</code></pre>
    </div>

    <p>The JOIN approach is usually better because it's explicit — you know exactly when you're loading the relation. Setting <code>fetch: EAGER</code> globally can lead to loading data you don't actually need.</p>

    <p>With eager loading, you go from <?php echo $queryCount; ?> queries down to just 1. The difference becomes really noticeable with larger datasets.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#joins" target="_blank" class="doc-link">
            Doctrine docs on DQL joins
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'N+1 query detected on %s relation - consider using JOIN FETCH',
        $relation,
    ),
];
