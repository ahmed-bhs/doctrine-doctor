<?php

declare(strict_types=1);

/**
 * Suggestion template for enabling lazy ghost objects.
 * Context variables: none required
 */

ob_start();
?>

<p>Enable lazy ghost objects in your Doctrine ORM configuration for better performance.</p>

<h4>What are Lazy Ghost Objects?</h4>

<p>Lazy Ghost Objects are a modern proxy mechanism introduced in Symfony 6.2. They provide several advantages over classic proxies:</p>

<ul>
<li><strong>Better performance</strong> — Minimal memory overhead and faster instantiation</li>
<li><strong>Work with final classes</strong> — No inheritance limitations</li>
<li><strong>No constructor side effects</strong> — Proxies don't call parent constructors</li>
<li><strong>Transparent instanceof checks</strong> — Proxies pass instanceof checks for the real class</li>
</ul>

<h4>Recommended configuration</h4>

<p>Update your Doctrine configuration to enable lazy ghost objects:</p>

<pre><code># config/packages/doctrine.yaml
doctrine:
    orm:
        enable_lazy_ghost_objects: true
</code></pre>

<p>If you need different settings per environment:</p>

<pre><code># config/packages/prod/doctrine.yaml
doctrine:
    orm:
        enable_lazy_ghost_objects: true

# config/packages/dev/doctrine.yaml
doctrine:
    orm:
        enable_lazy_ghost_objects: true
</code></pre>

<h4>Symfony Version Requirements</h4>

<p>Lazy Ghost Objects are available in Symfony 6.2+. Make sure your project meets this requirement.</p>

<h4>Migration from Classic Proxies</h4>

<p>If you're upgrading from an older Symfony version:</p>

<ol>
<li>Clear your cache: <code>php bin/console cache:clear</code></li>
<li>Update <code>doctrine.yaml</code> with the configuration above</li>
<li>Warm up the cache: <code>php bin/console cache:warmup</code></li>
<li>Test your application thoroughly</li>
</ol>

<p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/advanced-configuration.html#lazy-ghost-objects" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine ORM Lazy Ghost Objects Documentation</a></p>

<?php

$code = ob_get_clean();

return [
    'code' => $code,
    'description' => 'Enable lazy ghost objects for better performance and compatibility',
];
