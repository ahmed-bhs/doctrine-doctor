<?php

declare(strict_types=1);

/**
 * Suggestion template for ArrayCache in production.
 *
 * Context variables:
 * @var string $cache_type      - Type of cache (metadata, query, result)
 * @var string $current_config  - Current configuration value
 * @var string $cache_label     - Human-readable cache type label
 */

ob_start();
?>

<?php echo htmlspecialchars((string) $context->cache_label); ?> is using '<?php echo htmlspecialchars((string) $context->current_config); ?>' in production.

<p>This is a common misconfiguration that significantly impacts performance.</p>

<h3>Recommended configuration</h3>

<p>Use Redis (multi-server) or APCu (single-server):</p>

<pre><code># config/packages/prod/doctrine.yaml
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
                default_lifetime: 3600
</code></pre>

<h3>Why this matters</h3>

<ul>
<li>ArrayCache loses data after each request (no persistence)</li>
<li>Redis/APCu persists cache across all requests</li>
<li>Metadata parsing and DQL compilation are expensive operations</li>
</ul>

<h3>After configuration</h3>

<ol>
<li>Clear cache: <code>php bin/console cache:clear --env=prod</code></li>
<li>Warm up: <code>php bin/console cache:warmup --env=prod</code></li>
<li>Monitor cache hit rate in production</li>
</ol>

<?php

$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'ArrayCache in production causes severe performance degradation',
];
