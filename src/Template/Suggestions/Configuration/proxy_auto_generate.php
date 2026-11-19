<?php

declare(strict_types=1);

/**
 * Suggestion template for proxy auto-generation in production.
 * Context variables: none required
 * Note: This template doesn't display dynamic context variables, so htmlspecialchars() is not needed
 */
ob_start();
?>
<?php echo suggestionHeader('Disable proxy auto-generation in production'); ?>
<div class="suggestion-content">
<p><?php echo t('With auto-generation on, Doctrine checks the proxy files on disk when it loads entities. In production, generate them once at cache warmup instead.'); ?></p>

<div class="query-item"><pre><code class="language-yaml"># config/packages/doctrine.yaml
when@prod:
    doctrine:
        orm:
            auto_generate_proxy_classes: false</code></pre></div>

<p><?php echo t('Then deploy with <code>php bin/console cache:warmup --env=prod</code>, which writes the proxies.'); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/advanced-configuration.html#proxy-objects', 'Doctrine ORM Proxy Objects Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Disable proxy auto-generation in production'),
];
