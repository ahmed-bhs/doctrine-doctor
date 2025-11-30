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

<?php echo $context->cache_label; ?> is using '<?php echo $context->current_config; ?>' in production.

This is a common misconfiguration that significantly impacts performance.

## Recommended configuration

Use Redis (multi-server) or APCu (single-server):

```yaml
# config/packages/prod/doctrine.yaml
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
```

## Why this matters

- ArrayCache loses data after each request (no persistence)
- Redis/APCu persists cache across all requests
- Metadata parsing and DQL compilation are expensive operations

## After configuration

1. Clear cache: `php bin/console cache:clear --env=prod`
2. Warm up: `php bin/console cache:warmup --env=prod`
3. Monitor cache hit rate in production

<?php

$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'ArrayCache in production causes severe performance degradation',
];
