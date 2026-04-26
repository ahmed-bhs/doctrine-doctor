<?php

declare(strict_types=1);

$cacheLabel = (string) ($context['cache_label'] ?? 'Doctrine cache');
$cacheType = (string) ($context['cache_type'] ?? 'metadata');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="suggestion-header"><h4><?php echo $e($cacheLabel); ?> not configured in production</h4></div>
<div class="suggestion-content">
<div class="alert alert-danger"><?php echo $e($cacheLabel); ?> is missing from the <code>when@prod</code> section of <code>config/packages/doctrine.yaml</code>.</div>

<p>Without this cache, Doctrine repeats expensive work on every request in production.</p>

<h4>Recommended configuration</h4>
<p>Add the following to your <code>config/packages/doctrine.yaml</code>:</p>

<div class="query-item"><pre><code class="language-yaml">when@prod:
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

    framework:
        cache:
            pools:
                doctrine.system_cache_pool:
                    adapter: cache.adapter.apcu  # or cache.adapter.redis
                doctrine.result_cache_pool:
                    adapter: cache.adapter.apcu
                    default_lifetime: 3600</code></pre></div>

<h4>Why this matters</h4>
<ul>
<li><strong>metadata_cache_driver</strong>: without it, Doctrine reparses all entity annotations/attributes on every request (-50 to -80% performance)</li>
<li><strong>query_cache_driver</strong>: without it, DQL queries are recompiled on every execution (-30 to -50% performance)</li>
<li><strong>result_cache_driver</strong>: without it, no persistent query result caching is possible</li>
</ul>

<h4>After configuration</h4>
<ol>
<li>Clear cache: <code>php bin/console cache:clear --env=prod</code></li>
<li>Warm up: <code>php bin/console cache:warmup --env=prod</code></li>
</ol>

<p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/caching.html" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine ORM Caching Documentation</a></p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('%s is not configured in production (when@prod)', $cacheLabel),
];
