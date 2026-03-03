<?php

declare(strict_types=1);

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="suggestion-header"><h4>ArrayCache in production</h4></div>
<div class="suggestion-content">
<div class="alert alert-danger"><?php echo $e((string) $context->cache_label); ?> is using '<?php echo $e((string) $context->current_config); ?>' in production.</div>

<p>This is a common misconfiguration that significantly impacts performance.</p>

<h4>Recommended configuration</h4>
<p>Use Redis (multi-server) or APCu (single-server):</p>

<div class="query-item"><pre><code class="language-yaml"># config/packages/prod/doctrine.yaml
doctrine:
    orm:
        metadata_cache_driver:
            type: pool
            pool: doctrine.system_cache_pool
        query_cache_driver:
            type: pool
            pool: doctrine.system_cache_pool
        result_cache_driver:
            type: pool
            pool: doctrine.result_cache_pool

# config/packages/cache.yaml
framework:
    cache:
        pools:
            doctrine.system_cache_pool:
                adapter: cache.adapter.redis  # or cache.adapter.apcu
                default_lifetime: 3600
            doctrine.result_cache_pool:
                adapter: cache.adapter.redis
                default_lifetime: 3600</code></pre></div>

<h4>Why this matters</h4>
<ul>
<li>ArrayCache loses data after each request (no persistence)</li>
<li>Redis/APCu persists cache across all requests</li>
<li>Metadata parsing and DQL compilation are expensive operations</li>
</ul>

<h4>After configuration</h4>
<ol>
<li>Clear cache: <code>php bin/console cache:clear --env=prod</code></li>
<li>Warm up: <code>php bin/console cache:warmup --env=prod</code></li>
<li>Monitor cache hit rate in production</li>
</ol>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'ArrayCache in production causes severe performance degradation',
];
