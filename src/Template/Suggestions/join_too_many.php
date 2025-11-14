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
        <strong>Performance Issue</strong><br>
        Query contains <strong><?php echo $joinCount; ?> JOINs</strong> which is excessive (recommended: 5 max).
    </div>

    <h4>Performance Impact</h4>
    <ul>
        <li>1-3 JOINs: Excellent</li>
        <li>4-5 JOINs: Good</li>
        <li>6-7 JOINs: Acceptable</li>
        <li><strong>8+ JOINs: Poor (current: <?php echo $joinCount; ?>)</strong></li>
    </ul>

    <h4>Problem: Too Complex Query</h4>
    <p>Query contains <strong><?php echo $joinCount; ?> JOINs</strong>. Recommended: 5 max for optimal performance.</p>

    <h4>Solution: Split into Multiple Queries</h4>
    <div class="query-item">
        <pre><code class="language-php">// Instead of 1 query with <?php echo $joinCount; ?> JOINs, split into 2-3:

// Query 1: Orders with customer
$orders = $qb->select('o', 'c')
   ->from(Order::class, 'o')
   ->innerJoin('o.customer', 'c')
   ->getQuery()->getResult();

// Query 2: Load related data separately if needed
$customerIds = array_map(fn($o) => $o->getCustomer()->getId(), $orders);
$addresses = $em->createQuery('SELECT a FROM Address a WHERE a.customer IN (:ids)')
   ->setParameter('ids', $customerIds)
   ->getResult();

// Or use DTOs for read-only data (faster, less memory)</code></pre>
    </div>

    <p>Splitting queries typically improves performance by 50-70% and reduces memory usage.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/query-builder.html" target="_blank" class="doc-link">
            📖 Doctrine Query Builder Documentation →
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
