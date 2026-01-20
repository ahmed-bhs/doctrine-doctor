---
layout: default
title: Installation
parent: Getting Started
nav_order: 2
---

# Installation

Detailed installation guide for Doctrine Doctor.

---

## Requirements

- **PHP**: 8.2 or higher
- **Symfony**: 6.0+ or 7.x
- **Doctrine ORM**: 2.10+, 3.x, or 4.x

---

## Install via Composer

```bash
composer require --dev ahmed-bhs/doctrine-doctor
```

{: .warning }
> **Development Only**: Always install Doctrine Doctor as a development dependency (`--dev`). Never use it in production.

---

## Symfony Flex Auto-Configuration

If you're using Symfony Flex, the bundle is automatically configured. The recipe creates:

```text
config/
└── packages/
    └── dev/
        └── doctrine_doctor.yaml
```

with default configuration:

```yaml
doctrine_doctor:
    enabled: true
```

---

## Manual Configuration (Without Flex)

If you're not using Symfony Flex, register the bundle manually:

```php
<?php
// config/bundles.php

return [
    // ...
    AhmedBhs\DoctrineDoctor\DoctrineDoctorBundle::class => ['dev' => true],
];
```

Then create the configuration file:

```yaml
# config/packages/dev/doctrine_doctor.yaml
doctrine_doctor:
    enabled: true
```

---

## Verify Installation

Check that the bundle is loaded:

```bash
php bin/console debug:container --parameter=kernel.bundles
```

You should see `DoctrineDoctorBundle` in the list.

---

## Enable Backtraces (Optional but Recommended)

To see code location backtraces in issue reports:

```yaml
# config/packages/dev/doctrine.yaml
doctrine:
    dbal:
        profiling_collect_backtrace: true
```

---

## Troubleshooting

### Bundle Not Showing in Profiler

1. **Check environment**: Ensure you're in `dev` environment
2. **Clear cache**: Run `php bin/console cache:clear`
3. **Check bundle registration**: Verify bundle is in `config/bundles.php` with `['dev' => true]`

### No Issues Detected

1. **Execute queries**: Make sure your page executes database queries
2. **Check configuration**: Verify `doctrine_doctor.enabled` is `true`
3. **Review thresholds**: Some analyzers might have high thresholds

### Permission Issues

If using the Missing Index Analyzer, ensure your database user has `SELECT` permissions to execute `EXPLAIN` queries.

---

## Next Steps

- [Quick Start Guide](quick-start)
- [First Steps](first-steps)
- [Configuration Reference]({{ site.baseurl }}/user-guide/configuration)
