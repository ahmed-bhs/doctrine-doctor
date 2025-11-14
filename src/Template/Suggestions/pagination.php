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
        <strong><?php echo $e($method); ?></strong> returned <?php echo $resultCount; ?> results without pagination.
    </div>

    <h4>Solution: Add pagination</h4>
    <div class="query-item">
        <pre><code class="language-php">$page = 1;
$pageSize = 50;

$entities = $repository->createQueryBuilder('e')
    ->setFirstResult(($page - 1) * $pageSize)
    ->setMaxResults($pageSize)
    ->getQuery()
    ->getResult();

// Only 50 entities in memory at once</code></pre>
    </div>

    <p>Batch jobs: use <code>toIterable()</code> with periodic <code>flush()/clear()</code>. Pages: 10-50 for web, 100-1000 for APIs.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/tutorials/pagination.html" target="_blank" class="doc-link">
            📖 Doctrine pagination →
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
