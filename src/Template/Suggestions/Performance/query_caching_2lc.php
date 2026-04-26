<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$sql = (string) ($context['sql'] ?? '');
$count = max(0, (int) ($context['count'] ?? 0));
$avgTime = max(0.0, (float) ($context['avg_time'] ?? 0.0));

$sql = html_entity_decode($sql, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$e = static fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="suggestion-header"><h4>Doctrine 2LC Opportunity</h4></div>
<div class="suggestion-content">
<div class="alert alert-warning">
    Detected <?php echo $count; ?> fast repeated entity-load queries (avg <?php echo number_format($avgTime, 2); ?>ms).
    Consider Doctrine second-level cache to reduce database round-trips.
</div>

<h4>Representative query</h4>
<div class="query-item"><pre><code class="language-sql"><?php echo $e($sql); ?></code></pre></div>

<h4>Suggested approach</h4>
<div class="query-item"><pre><code class="language-php">#[ORM\Cache(usage: 'READ_ONLY', region: 'entity_region')]
class Product
{
}

// Enable second-level cache in Doctrine config and tune region TTL.</code></pre></div>

<p><strong>Safety notes:</strong> 2LC is best for stable/read-mostly entities. Validate invalidation behavior and consistency needs.</p>

<p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/second-level-cache.html" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine ORM Second Level Cache</a></p>
</div>
<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => sprintf('Enable Doctrine 2LC for repeated fast entity loads (%d occurrences)', $count),
];

