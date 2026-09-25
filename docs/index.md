---
layout: home
title: Home
nav_order: 1
description: "Doctrine ORM checks for Symfony: inspect real request queries in the Web Profiler and run source, mapping, and optional database audits in CI."
permalink: /
---

# Doctrine Doctor
{: .fs-9 }

Doctrine ORM checks for your Symfony app — in the profiler and CI
{: .fs-6 .fw-300 }

[Get started now](getting-started/quick-start){: .btn .btn-primary .fs-5 .mb-4 .mb-md-0 .mr-2 }
[View on GitHub](https://github.com/ahmed-bhs/doctrine-doctor){: .btn .fs-5 .mb-4 .mb-md-0 }

---

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4+-777BB4.svg?logo=php&logoColor=white)](https://php.net)
[![Symfony 6.x | 7.x | 8.x](https://img.shields.io/badge/Symfony-6.x%20%7C%207.x%20%7C%208.x-000000.svg?logo=symfony&logoColor=white)](https://symfony.com)
[![Doctrine ORM](https://img.shields.io/badge/Doctrine-3.x%20%7C%204.x-FC6A31.svg?logo=doctrine&logoColor=white)](https://www.doctrine-project.org)
[![License MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://github.com/ahmed-bhs/doctrine-doctor/blob/main/LICENSE)
[![CI](https://github.com/ahmed-bhs/doctrine-doctor/workflows/CI/badge.svg)](https://github.com/ahmed-bhs/doctrine-doctor/actions)
[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-Level%208-brightgreen.svg)](https://phpstan.org)

---

## Find issues while you work and before you merge

Doctrine Doctor complements tools such as PHPStan and Psalm with checks focused on Doctrine:

- **In the profiler**, see query problems from a real request, including N+1 queries and slow SQL.
- **In CI**, check source code, Doctrine mappings, and configuration on pull requests.
- **When useful**, include audits of a live database with `doctrine:doctor:analyze --with-database`.
- **In the profiler**, follow a finding back to the code that triggered it and review a suggested fix.

<p align="center">
  <img src="https://github.com/ahmed-bhs/doctrine-doctor-assets/raw/main/demo.png" alt="Doctrine Doctor Demo" width="100%">
</p>

---

## Features

### 100 Built-in Analyzers

- **Performance** — Detects N+1 queries, missing database indexes, slow queries, excessive hydration,
  findAll() without limits, setMaxResults() with collection joins, too many JOINs, and query caching
  opportunities

- **Security** — Identifies DQL/SQL injection vulnerabilities, QueryBuilder SQL injection risks,
  sensitive data exposure in serialization, unprotected sensitive fields, and insecure random generators

- **Code Quality** — Detects cascade configuration issues, bidirectional inconsistencies,
  missing orphan removal, type mismatches, float usage for money, uninitialized collections,
  EntityManager in entities, and architectural violations

- **Configuration** — Validates database charset/collation settings, timezone handling,
  Gedmo trait configurations, MySQL strict mode, and other database-level configurations

See [Profiler and CI Checks](user-guide/execution-modes) to choose where to run each check.

---

## ⚡ Quick Start (30 seconds)

**Step 1: Install**

```bash
composer require --dev ahmed-bhs/doctrine-doctor
```

**Step 2: That's it!**

Auto-configured via [Symfony Flex](https://github.com/symfony/recipes-contrib/pull/1882). No YAML, no configuration files needed.

**Step 3: See it in action**

1. Refresh any page in your Symfony app (in `dev` environment)
2. Open the **Symfony Web Profiler** (bottom toolbar)
3. Click the **"Doctrine Doctor"** panel 🩺

---

## Configuration (Optional)

Configure thresholds in `config/packages/dev/doctrine_doctor.yaml`:

```yaml
doctrine_doctor:
    analyzers:
        n_plus_one:
            threshold: 5  # default, lower to 3 to be stricter
        slow_query:
            threshold: 100  # milliseconds (default)
```

**Enable backtraces** to see WHERE in your code issues originate:

```yaml
# config/packages/dev/doctrine.yaml
doctrine:
    dbal:
        profiling_collect_backtrace: true
```

[Full configuration reference →](user-guide/configuration)

---

## Example: N+1 Query Detection

### Problem: Template triggers lazy loading

```php
// Controller
$users = $repository->findAll();

// Template
{% raw %}{% for user in users %}
    {{ user.profile.bio }}
{% endfor %}{% endraw %}
```

*Triggers 100 queries*

### Detection: Doctrine Doctor detects N+1

- 100 queries instead of 1
- Shows exact query count, execution time
- Suggests eager loading

*Real-time detection*

### Solution: Eager load with JOIN

```php
$users = $repository
    ->createQueryBuilder('u')
    ->leftJoin('u.profile', 'p')
    ->addSelect('p')
    ->getQuery()
    ->getResult();
```

*Single query*

---

## Documentation

| Document | Description |
|----------|-------------|
| [**Configuration Reference**](user-guide/configuration) | Comprehensive guide to all configuration options - customize analyzers, thresholds, and outputs to match your workflow |
| [**Profiler and CI Checks**](user-guide/execution-modes) | Choose where to run Doctrine checks |
| [**Full Analyzers List**](user-guide/analyzers) | Browse the built-in checks for performance, security, code quality, and configuration |
| [**Architecture Guide**](advanced/architecture) | Deep dive into system design, architecture patterns, and technical internals |
| [**Template Security**](advanced/template-security) | Essential security best practices for PHP templates - prevent XSS attacks and ensure safe template rendering |

---

## Contributing

We welcome contributions! See our [Contributing Guide](contributing/overview) for details.

---

## License

MIT License - see [LICENSE](about/license) for details.

---

**Created by [Ahmed EBEN HASSINE](https://github.com/ahmed-bhs)**

[![Sponsor on GitHub](https://img.shields.io/static/v1?label=Sponsor&message=GitHub&logo=github&style=for-the-badge&color=blue)](https://github.com/sponsors/ahmed-bhs)
[![Buy Me A Coffee](https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png)](https://www.buymeacoffee.com/w6ZhBSGX2)
