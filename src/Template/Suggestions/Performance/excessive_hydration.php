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
<?php echo suggestionHeader('Hydrate fewer rows or lighter results'); ?>
<div class="suggestion-content">
<p><?php echo t('The query hydrated %d rows into managed entities, each one tracked by the unit of work.', (int) $rowCount); ?>
<?php echo t('A read-only screen rarely needs that: page the result or hydrate plain data.'); ?></p>

<div class="query-item"><pre><code class="language-php">// Page the result
$query-&gt;setFirstResult($offset)-&gt;setMaxResults(50);

// Or read DTOs, not tracked by the unit of work
$em-&gt;createQuery('SELECT NEW App\DTO\ProductRow(p.id, p.name) FROM App\Entity\Product p');

// Or arrays
$rows = $query-&gt;getArrayResult();</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/dql-doctrine-query-language.html#query-result-formats-hydration-modes', 'Doctrine ORM Hydration Modes'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Query hydrated %d rows: paginate or use DTO or array hydration', $rowCount),
];
