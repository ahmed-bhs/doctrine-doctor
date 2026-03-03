<?php

declare(strict_types=1);

/**
 * Suggestion template for proxy auto-generation in production.
 *
 * Context variables: none required
 * Note: This template doesn't display dynamic context variables, so htmlspecialchars() is not needed
 */

ob_start();
?>

<p>Proxy auto-generation should be disabled in production.</p>

<h4>Current issue</h4>

<p>When enabled, Doctrine checks the filesystem on every request to see if proxy classes need regeneration. This causes unnecessary I/O operations and slows down entity loading.</p>

<h4>Recommended configuration</h4>

<pre><code># config/packages/prod/doctrine.yaml
doctrine:
    orm:
        auto_generate_proxy_classes: false
</code></pre>

<h4>Deployment workflow</h4>

<p>Generate proxies during deployment, not at runtime:</p>

<pre><code>php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
</code></pre>

<h4>Development environment</h4>

<p>Keep auto-generation enabled for convenience:</p>

<pre><code># config/packages/dev/doctrine.yaml
doctrine:
    orm:
        auto_generate_proxy_classes: true
</code></pre>

<?php

$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Disable proxy auto-generation in production for better performance',
];
