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
    <h4>Consider adding pagination</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong><?php echo $e($method); ?></strong> returned <strong><?php echo $resultCount; ?> results</strong> without pagination. This can lead to memory issues as your dataset grows.
    </div>

    <p>Loading all <?php echo $resultCount; ?> entities at once means they're all sitting in memory. For smaller datasets this is fine, but it doesn't scale well.</p>

    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php">$entities = $repository->findAll();
// Loads all <?php echo $resultCount; ?> entities into memory</code></pre>
    </div>

    <h4>Add pagination</h4>
    <div class="query-item">
        <pre><code class="language-php">// Load in chunks
$page = 1;
$pageSize = 50;

$entities = $repository->createQueryBuilder('e')
    ->setFirstResult(($page - 1) * $pageSize)
    ->setMaxResults($pageSize)
    ->getQuery()
    ->getResult();

// Only 50 entities in memory at once</code></pre>
    </div>

    <h4>Other approaches</h4>

    <h5>Using Doctrine Paginator</h5>
    <div class="query-item">
        <pre><code class="language-php">use Doctrine\ORM\Tools\Pagination\Paginator;

$query = $entityManager->createQuery('SELECT e FROM Entity e')
    ->setFirstResult(0)
    ->setMaxResults(50);

$paginator = new Paginator($query);

foreach ($paginator as $entity) {
    // Process entity
}

// Total count: $paginator->count()</code></pre>
    </div>

    <h5>Batch processing for background jobs</h5>
    <div class="query-item">
        <pre><code class="language-php">// When you need to process everything
$batchSize = 20;
$i = 0;

$query = $entityManager->createQuery('SELECT e FROM Entity e');
$iterableResult = $query->toIterable();

foreach ($iterableResult as $entity) {
    // Process entity

    if (($i % $batchSize) === 0) {
        $entityManager->flush();
        $entityManager->clear();
    }
    $i++;
}</code></pre>
    </div>

    <p>Typical page sizes: 10-50 for web pages, 100-1000 for APIs. Always set a reasonable maximum to prevent abuse.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/tutorials/pagination.html" target="_blank" class="doc-link">
            📖 Doctrine pagination docs
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Loading %d results without pagination in %s',
        $resultCount,
        $method,
    ),
];
