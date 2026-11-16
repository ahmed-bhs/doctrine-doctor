<?php

declare(strict_types=1);

/**
 * Suggestion template for enabling lazy ghost objects.
 * Context variables: none required
 */
ob_start();
?>
<?php echo suggestionHeader('Enable native lazy objects'); ?>
<div class="suggestion-content">
<p>Doctrine still builds generated proxy classes for lazy loading. Native lazy objects (PHP 8.4, ORM 3.4+) need no generated code and also work with final entity classes.</p>

<div class="query-item"><pre><code class="language-yaml"># config/packages/doctrine.yaml
doctrine:
    orm:
        enable_native_lazy_objects: true</code></pre></div>

<p>On an older ORM, set <code>enable_lazy_ghost_objects: true</code> instead.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/advanced-configuration.html#lazy-ghost-objects', 'Doctrine ORM Lazy Ghost Objects Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => 'Enable native lazy objects in the Doctrine configuration',
];
