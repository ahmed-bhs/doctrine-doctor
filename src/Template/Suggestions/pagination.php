<?php

declare(strict_types=1);

/**
 * Template for Pagination suggestions.
 * Context variables:
 * @var string $method - Method name (e.g., "findAll")
 * @var int    $result_count - Number of results returned
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
['method' => $method, 'result_count' => $resultCount] = $context;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

// Start output buffering
ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
        <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/>
    </svg>
    <h4>Suggested Fix: Add Pagination</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Missing Pagination</strong><br>
        Method <code><?php echo $e($method); ?></code> returned <strong><?php echo $resultCount; ?> results</strong> without pagination.
        Loading large datasets can cause memory issues and slow performance.
    </div>

    <h4>Problem: Loading All Results</h4>
    <div class="query-item">
        <pre><code class="language-php">// BAD: Loads all <?php echo $resultCount; ?> results into memory
$entities = $repository->findAll();
// Memory usage: Very high with <?php echo $resultCount; ?> entities!</code></pre>
    </div>

    <h4>Solution: Use Pagination</h4>
    <div class="query-item">
        <pre><code class="language-php">//  GOOD: Load data in chunks
$page = 1;
$pageSize = 50;

$entities = $repository->createQueryBuilder('e')
    ->setFirstResult(($page - 1) * $pageSize)
    ->setMaxResults($pageSize)
    ->getQuery()
    ->getResult();

// Memory usage: Only 50 entities at a time</code></pre>
    </div>

    <h4>Alternative Solutions</h4>

    <h5>Option 1: Use Doctrine Paginator</h5>
    <div class="query-item">
        <pre><code class="language-php">use Doctrine\ORM\Tools\Pagination\Paginator;

$query = $entityManager->createQuery('SELECT e FROM Entity e')
    ->setFirstResult(0)
    ->setMaxResults(50);

$paginator = new Paginator($query);

assert(is_iterable($paginator), '$paginator must be iterable');


foreach ($paginator as $entity) {
    // Process entity
}

// Total count available: $paginator->count()</code></pre>
    </div>

    <h5>Option 2: Iterate with Batch Processing</h5>
    <div class="query-item">
        <pre><code class="language-php">// For processing all entities efficiently
$batchSize = 20;
$i = 0;

$query = $entityManager->createQuery('SELECT e FROM Entity e');
$iterableResult = $query->toIterable();

assert(is_iterable($iterableResult), '$iterableResult must be iterable');


foreach ($iterableResult as $entity) {
    // Process entity

    if (($i % $batchSize) === 0) {
        $entityManager->flush();
        $entityManager->clear();
    }
    $i++;
}</code></pre>
    </div>

    <h4>Best Practices</h4>
    <ul>
        <li>Use pagination for lists displayed to users</li>
        <li>Use batch processing for background tasks</li>
        <li>Typical page size: 10-50 items for web pages, 100-1000 for API</li>
        <li>Always set a maximum limit to prevent abuse</li>
        <li>Consider using cursor-based pagination for large datasets</li>
    </ul>

    <div class="alert alert-info">
        ℹ️ <strong>Expected Performance Improvement:</strong><br>
        <ul>
            <li>Memory usage: Reduced significantly (only loading 50 instead of <?php echo $resultCount; ?>)</li>
            <li>Faster initial page load</li>
            <li>Better user experience with progressive loading</li>
        </ul>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/tutorials/pagination.html" target="_blank" class="doc-link">
            📖 Doctrine Pagination Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Add pagination to %s to avoid loading %d results at once',
        $method,
        $resultCount,
    ),
];
