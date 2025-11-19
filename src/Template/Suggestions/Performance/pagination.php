<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $method
 * @var mixed $resultCount
 * @var mixed $context
 */
['method' => $method, 'result_count' => $resultCount] = $context;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

// Start output buffering
ob_start();
?>

<div class="suggestion-header">
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
