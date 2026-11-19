<?php

declare(strict_types=1);

$cacheLabel = (string) ($context['cache_label'] ?? 'Doctrine cache');
$cacheType = (string) ($context['cache_type'] ?? 'metadata');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Configure the Doctrine cache for production'); ?>
<div class="suggestion-content">
<p><?php echo t('%s is missing from the <code>when@prod</code> section of <code>config/packages/doctrine.yaml</code>. Without it, Doctrine repeats metadata parsing or DQL compilation on every request.', $e($cacheLabel)); ?></p>

<div class="query-item"><pre><code class="language-yaml"># config/packages/doctrine.yaml
when@prod:
    doctrine:
        orm:
            metadata_cache_driver: { type: pool, pool: doctrine.system_cache_pool }
            query_cache_driver: { type: pool, pool: doctrine.system_cache_pool }
            result_cache_driver: { type: pool, pool: doctrine.result_cache_pool }
    framework:
        cache:
            pools:
                doctrine.system_cache_pool: { adapter: cache.system }
                doctrine.result_cache_pool: { adapter: cache.app }</code></pre></div>

<p><?php echo t('For the result cache, use Redis (<code>cache.adapter.redis</code>) when several servers must share it, or APCu (<code>cache.adapter.apcu</code>) on a single server.'); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/caching.html', 'Doctrine ORM Caching Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Configure %s in the when@prod section', $cacheLabel),
];
