<?php

declare(strict_types=1);

/**
 * Template for Excessive Hydration suggestion.
 * Context variables:
 * - rowCount: int - Number of rows detected
 * - threshold: int - Threshold for excessive hydration
 * - criticalThreshold: int - Critical threshold
 */
$rowCount = $context['row_count'] ?? 0;
$threshold = $context['threshold'] ?? 10;
$criticalThreshold = $context['critical_threshold'] ?? 100;

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-content">
    <div class="suggestion-options">
        <pre><code class="language-php">// Array hydration (faster for read-only)
$result = $query->getResult(Query::HYDRATE_ARRAY);

// DTO hydration (best performance + type-safe)
SELECT NEW App\DTO\MyDTO(e.id, e.name) FROM Entity e

// PARTIAL objects (select only needed fields)
SELECT PARTIAL e.{id, name} FROM Entity e

// Pagination (limit results)
$query->setFirstResult($offset)->setMaxResults($limit);</code></pre>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Query returned %d rows which may cause significant hydration overhead. Consider limiting results or using lighter hydration modes.',
        $rowCount,
    ),
];
