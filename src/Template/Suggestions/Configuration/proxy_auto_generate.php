<?php

declare(strict_types=1);

/**
 * Suggestion template for proxy auto-generation in production.
 *
 * Context variables: none required
 */

ob_start();
?>

Proxy auto-generation should be disabled in production.

## Current issue

When enabled, Doctrine checks the filesystem on every request to see if proxy classes need regeneration. This causes unnecessary I/O operations and slows down entity loading.

## Recommended configuration

```yaml
# config/packages/prod/doctrine.yaml
doctrine:
    orm:
        auto_generate_proxy_classes: false
```

## Deployment workflow

Generate proxies during deployment, not at runtime:

```bash
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

## Development environment

Keep auto-generation enabled for convenience:

```yaml
# config/packages/dev/doctrine.yaml
doctrine:
    orm:
        auto_generate_proxy_classes: true
```

<?php

$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Disable proxy auto-generation in production for better performance',
];
