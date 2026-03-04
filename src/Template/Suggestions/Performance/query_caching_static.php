<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$sql = $context['sql'] ?? 'SELECT * FROM static_table';
$table_name = $context['table_name'] ?? 'static_table';

$sql = html_entity_decode($sql, ENT_QUOTES | ENT_HTML5, 'UTF-8');

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="suggestion-header"><h4>Cache query on static table</h4></div>
<div class="suggestion-content">
<div class="alert alert-info">Query on static table <code><?php echo $e($table_name); ?></code> (rarely-changing lookup data)</div>

<h4>Query</h4>
<div class="query-item"><pre><code class="language-sql"><?php echo $e($sql); ?></code></pre></div>

<h4>Solution: Cache for 24h</h4>
<div class="query-item"><pre><code class="language-php">$query->useResultCache(true, 86400, '<?php echo $e($table_name); ?>_all');</code></pre></div>

<p><strong>Impact:</strong> 50-200x faster, 99% less DB load.</p>

<h4>Cache durations</h4>
<ul>
    <li>Never changes (countries): 604800s (1 week)</li>
    <li>Rarely changes (categories): 86400s (24h)</li>
    <li>Occasionally (settings): 3600s (1h)</li>
</ul>

<h4>Invalidation (when data changes)</h4>
<div class="query-item"><pre><code class="language-php">$cacheDriver->delete('<?php echo $e($table_name); ?>_all');</code></pre></div>

<p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/caching.html" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine ORM Caching</a></p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Cache queries on static table %s for better performance', $table_name),
];
