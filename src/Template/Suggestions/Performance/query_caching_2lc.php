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
<?php echo suggestionHeader('Cache the entity in the second-level cache'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    The same entity load ran <?php echo $count; ?> times, <?php echo number_format($avgTime, 2); ?>ms on average.
    The second-level cache serves the entity after the first load without a query.
</div>

<div class="query-item"><pre><code class="language-php">#[ORM\Entity]
#[ORM\Cache(usage: 'READ_ONLY', region: 'product')]
class Product
{
}

// config/packages/doctrine.yaml: doctrine.orm.second_level_cache.enabled: true</code></pre></div>

<p>Use <code>READ_ONLY</code> only for entities that never change; use <code>NONSTRICT_READ_WRITE</code> or <code>READ_WRITE</code> for the others.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/second-level-cache.html', 'Doctrine ORM Second Level Cache'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => sprintf('Enable the second-level cache for this entity (%d repeated loads)', $count),
];
