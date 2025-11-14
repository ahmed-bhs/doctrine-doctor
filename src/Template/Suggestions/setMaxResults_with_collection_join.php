<?php

declare(strict_types=1);

/**
 * Template for setMaxResults() with Collection Join suggestions.
 * Context variables:
 * @var string $entity_hint - Entity name hint from SQL analysis
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
['entity_hint' => $entityHint] = $context;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

// Start output buffering
ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path fill-rule="evenodd" d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
    </svg>
    <h4>setMaxResults() with collection join</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>Data loss risk</strong> - Using <code>setMaxResults()</code> with fetch-joined collections causes LIMIT to apply to SQL rows instead of entities, resulting in incomplete collections.
    </div>

    <p>When you join a collection and use setMaxResults(), the LIMIT applies to database rows, not entities. If an order has 5 items, you might only get 2 of them.</p>

    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php">// Incomplete collections
$query = $em->createQueryBuilder()
    ->select('order', 'items')
    ->from(Order::class, 'order')
    ->leftJoin('order.items', 'items')
    ->setMaxResults(10)  // LIMIT applies to rows
    ->getQuery();

$orders = $query->getResult();</code></pre>
    </div>

    <h4>Use Doctrine Paginator</h4>
    <div class="query-item">
        <pre><code class="language-php">use Doctrine\ORM\Tools\Pagination\Paginator;

$query = $em->createQueryBuilder()
    ->select('order', 'items')
    ->from(Order::class, 'order')
    ->leftJoin('order.items', 'items')
    ->setMaxResults(10)
    ->getQuery();

// Paginator executes 2 queries to load complete collections
$paginator = new Paginator($query, $fetchJoinCollection = true);
$orders = iterator_to_array($paginator);</code></pre>
    </div>

    <p>The Paginator runs 2 optimized queries to ensure all collection items are loaded correctly.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/current/tutorials/pagination.html" target="_blank" class="doc-link">
            📖 Doctrine pagination docs
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use Paginator with setMaxResults() and collection joins to prevent data loss',
];
