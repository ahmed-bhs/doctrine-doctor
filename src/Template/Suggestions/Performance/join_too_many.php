<?php

declare(strict_types=1);

/**
 * Template for Too Many JOINs suggestion.
 * Context variables:
 * @var int    $join_count - Number of JOINs detected
 * @var string $sql - SQL query excerpt
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
$joinCount = $context['join_count'] ?? null;
$sql = $context['sql'] ?? null;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Too Many JOINs in Single Query</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        Query with <strong><?php echo $joinCount; ?> JOINs</strong> exceeds recommendation (5 max).
    </div>

    <h4>Solution: Split into multiple queries</h4>
    <div class="query-item">
        <pre><code class="language-php">// Query 1: Orders with customer
$orders = $qb->select('o', 'c')
   ->from(Order::class, 'o')
   ->innerJoin('o.customer', 'c')
   ->getQuery()->getResult();

// Query 2: Load related data separately
$customerIds = array_map(fn($o) => $o->getCustomer()->getId(), $orders);
$addresses = $em->createQuery('SELECT a FROM Address a WHERE a.customer IN (:ids)')
   ->setParameter('ids', $customerIds)
   ->getResult();</code></pre>
    </div>

    <p>Splitting improves performance by 50-70%. Consider DTOs for read-only data.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/query-builder.html" target="_blank" class="doc-link">
            📖 Doctrine Query Builder →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Split query with %d JOINs into multiple smaller queries or use DTOs',
        $joinCount,
    ),
];
