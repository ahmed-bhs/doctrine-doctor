<?php

declare(strict_types=1);

/**
 * Suggestion template for enabling lazy ghost objects.
 * Context variables: none required
 */

ob_start();
?>

<p>Enable native lazy objects in Doctrine ORM for lighter proxies, support for final classes, and safer instantiation.</p>

<h4>Recommended configuration</h4>

<pre><code># config/packages/doctrine.yaml
doctrine:
    orm:
        enable_native_lazy_objects: true
</code></pre>

<p>If you configure Doctrine per environment, enable it there as well:</p>

<pre><code># config/packages/prod/doctrine.yaml
doctrine:
    orm:
        enable_native_lazy_objects: true

# config/packages/dev/doctrine.yaml
doctrine:
    orm:
        enable_native_lazy_objects: true
</code></pre>

<p>Available in Symfony 6.2+. On newer DoctrineBundle versions, native lazy objects are enabled by default.</p>

<p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/advanced-configuration.html#lazy-ghost-objects" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine ORM Lazy Ghost Objects Documentation</a></p>

<?php

$code = ob_get_clean();

return [
    'code' => $code,
    'description' => 'Enable lazy ghost objects for better performance and compatibility',
];
