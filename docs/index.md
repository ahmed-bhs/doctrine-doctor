# Doctrine Doctor

<p align="center">
  <img src="images/logo.png" alt="Doctrine Doctor Logo" width="150">
</p>

<p align="center">
  <strong>Runtime Analysis Tool for Doctrine ORM — Integrated into Symfony Web Profiler</strong>
</p>

<p align="center">
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.2+-777BB4.svg?logo=php&logoColor=white" alt="PHP 8.2+"></a>
  <a href="https://symfony.com"><img src="https://img.shields.io/badge/Symfony-6.0%2B%20%7C%207.x-000000.svg?logo=symfony&logoColor=white" alt="Symfony 6.0+ | 7.x"></a>
  <a href="https://www.doctrine-project.org"><img src="https://img.shields.io/badge/Doctrine-2.10%2B%20%7C%203.x%20%7C%204.x-FC6A31.svg?logo=doctrine&logoColor=white" alt="Doctrine ORM"></a>
  <br>
  <a href="https://github.com/ahmed-bhs/doctrine-doctor/blob/main/LICENSE"><img src="https://img.shields.io/badge/License-MIT-green.svg" alt="License MIT"></a>
  <a href="https://github.com/ahmed-bhs/doctrine-doctor/actions"><img src="https://github.com/ahmed-bhs/doctrine-doctor/workflows/CI/badge.svg" alt="CI"></a>
  <a href="https://phpstan.org"><img src="https://img.shields.io/badge/PHPStan-Level%208-brightgreen.svg" alt="PHPStan Level 8"></a>
  <a href="https://www.php-fig.org/psr/psr-12/"><img src="https://img.shields.io/badge/Code%20Style-PSR--12-blue.svg" alt="Code Style"></a>
  <a href="https://packagist.org/packages/ahmed-bhs/doctrine-doctor"><img src="https://img.shields.io/packagist/v/ahmed-bhs/doctrine-doctor.svg" alt="Packagist Version"></a>
</p>

---

## Why Runtime Analysis?

Unlike static analysis tools (PHPStan, Psalm) that analyze code without execution, Doctrine Doctor:

- **Detects runtime-only issues**: N+1 queries, actual query performance, missing indexes on real database
- **Analyzes real execution context**: Actual parameter values, data volumes, execution plans
- **Integrated into your workflow**: Results appear directly in Symfony Web Profiler during development
  - 📍 **Backtrace**: Points to exact template line
  - 💡 **Suggestion**: Use `->addSelect(..)` to eager load authors

<p align="center">
  <img src="https://github.com/ahmed-bhs/doctrine-doctor-assets/raw/main/demo.gif" alt="Doctrine Doctor Demo" width="100%">
</p>

---

## Features

### 66 Specialized Analyzers

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

[Full configuration reference →](CONFIGURATION.md)

---

## Example: N+1 Query Detection

=== "Problem"

    **Template triggers lazy loading**

    ```php
    // Controller
    $users = $repository->findAll();

    // Template
    {% for user in users %}
        {{ user.profile.bio }}
    {% endfor %}
    ```

    _Triggers 100 queries_

=== "Detection"

    **Doctrine Doctor detects N+1**

    - 100 queries instead of 1
    - Shows exact query count, execution time
    - Suggests eager loading

    _Real-time detection_

=== "Solution"

    **Eager load with JOIN**

    ```php
    $users = $repository
        ->createQueryBuilder('u')
        ->leftJoin('u.profile', 'p')
        ->addSelect('p')
        ->getQuery()
        ->getResult();
    ```

    _Single query_

---

## Documentation

| Document | Description |
|----------|-------------|
| [**Configuration Reference**](CONFIGURATION.md) | Comprehensive guide to all configuration options - customize analyzers, thresholds, and outputs to match your workflow |
| [**Full Analyzers List**](ANALYZERS.md) | Complete catalog of all 66 analyzers covering performance, security, code quality, and configuration |
| [**Architecture Guide**](ARCHITECTURE.md) | Deep dive into system design, architecture patterns, and technical internals |
| [**Template Security**](TEMPLATE_SECURITY.md) | Essential security best practices for PHP templates - prevent XSS attacks and ensure safe template rendering |

---

## Contributing

We welcome contributions! See our [Contributing Guide](contributing/overview.md) for details.

---

## License

MIT License - see [LICENSE](about/license.md) for details.

---

<p align="center">
  <strong>Created by <a href="https://github.com/ahmed-bhs">Ahmed EBEN HASSINE</a></strong>
</p>

<p align="center">
  <a href="https://github.com/sponsors/ahmed-bhs" target="_blank">
    <img src="https://img.shields.io/static/v1?label=Sponsor&message=GitHub&logo=github&style=for-the-badge&color=blue" alt="Sponsor me on GitHub" height="32">
  </a>
  <a href="https://www.buymeacoffee.com/w6ZhBSGX2" target="_blank">
    <img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" alt="Buy Me A Coffee" height="32">
  </a>
</p>
