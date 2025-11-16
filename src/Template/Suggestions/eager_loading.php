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
    <h4>Suggested Fix: Eager Loading</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>N+1 Query Detected</strong><br>
        Detected <strong><?php echo $queryCount; ?> sequential queries</strong> loading <code><?php echo $e($relation); ?></code> relation.
        This happens when accessing a lazy-loaded relation inside a loop.
    </div>

    <h4>Problem: Lazy Loading in Loop</h4>
    <div class="query-item">
        <pre><code class="language-php">// BAD: Lazy loading in loop causes N+1 queries
$entities = $repository->findAll();
assert(is_iterable($entities), '$entities must be iterable');

foreach ($entities as $entity) {
    $entity->get<?php echo ucfirst($relation); ?>(); // Each call triggers a separate query!
}
// Result: <?php echo $queryCount; ?> queries instead of 1</code></pre>
    </div>

    <h4>Solution: Eager Loading with JOIN FETCH</h4>
    <div class="query-item">
        <pre><code class="language-php">//  GOOD: Use JOIN FETCH for eager loading
$entities = $entityManager
    ->createQuery('
        SELECT e, r
        FROM App\\Entity\\<?php echo $e($entity); ?> e
        JOIN e.<?php echo $e($relation); ?> r
    ')
    ->getResult();

assert(is_iterable($entities), '$entities must be iterable');


foreach ($entities as $entity) {
    $entity->get<?php echo ucfirst($relation); ?>(); // No query! Already loaded
}
// Result: 1 query total</code></pre>
    </div>

    <h4>Alternative Solutions</h4>

    <h5>Option 1: Repository Method</h5>
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

    <h5>Option 2: Doctrine Fetch Mode</h5>
    <div class="query-item">
        <pre><code class="language-php">// In your entity
#[ORM\ManyToOne(fetch: 'EAGER')]
private ?RelatedEntity $<?php echo $e($relation); ?> = null;</code></pre>
    </div>

    <h4>Best Practices</h4>
    <ul>
        <li>Always use JOIN FETCH when you know you'll need the relation</li>
        <li>Avoid <code>fetch: EAGER</code> globally, use it per query instead</li>
        <li>Monitor query count with Doctrine profiler</li>
        <li>Consider using <code>EXTRA_LAZY</code> for large collections</li>
    </ul>

    <div class="alert alert-info">
        ℹ️ <strong>Expected Performance Improvement:</strong><br>
        <ul>
            <li>Query count: Reduced from <?php echo $queryCount; ?> to 1</li>
            <li>Especially beneficial for large datasets</li>
            <li>Significant speedup with proper <code>indexes</code></li>
        </ul>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#joins" target="_blank" class="doc-link">
            📖 Doctrine DQL Joins Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Use JOIN FETCH to eagerly load the %s relation instead of lazy loading in a loop',
        $relation,
    ),
];
