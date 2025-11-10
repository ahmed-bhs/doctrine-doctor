# Doctrine Doctor

<img src="docs/images/logo.png" alt="Doctrine Doctor Logo" width="80" align="right">

**Runtime Analysis Tool for Doctrine ORM — Integrated into Symfony Web Profiler**

[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1+-777BB4.svg?logo=php&logoColor=white)](https://php.net)
[![Symfony 6.4+ | 7.x](https://img.shields.io/badge/Symfony-6.4%2B%20%7C%207.x-000000.svg?logo=symfony&logoColor=white)](https://symfony.com)
[![Doctrine ORM](https://img.shields.io/badge/Doctrine-2.10%2B%20%7C%203.x%20%7C%204.x-FC6A31.svg?logo=doctrine&logoColor=white)](https://www.doctrine-project.org)
[![License MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![CI](https://github.com/ahmed-bhs/doctrine-doctor/workflows/CI/badge.svg)](https://github.com/ahmed-bhs/doctrine-doctor/actions)
[![PHPStan Level 9](https://img.shields.io/badge/PHPStan-Level%208-brightgreen.svg)](https://phpstan.org)
[![Code Style](https://img.shields.io/badge/Code%20Style-PSR--12-blue.svg)](https://www.php-fig.org/psr/psr-12/)
[![Packagist Version](https://img.shields.io/packagist/v/ahmed-bhs/doctrine-doctor.svg)](https://packagist.org/packages/ahmed-bhs/doctrine-doctor)

<table>
<tr>
<td width="50%" valign="top">

**Why Runtime Analysis?**

Unlike static analysis tools (PHPStan, Psalm) that analyze code without execution, Doctrine Doctor:

- **Detects runtime-only issues**: N+1 queries, actual query performance, missing indexes on real database
- **Analyzes real execution context**: Actual parameter values, data volumes, execution plans
- **Integrated into your workflow**: Results appear directly in Symfony Web Profiler during development

</td>
<td width="50%" align="center" valign="top" style="background: url('https://github.com/ahmed-bhs/doctrine-doctor-assets/raw/main/demo-thumbnail.png') no-repeat center; background-size: contain;">

![Doctrine Doctor Demo](https://github.com/ahmed-bhs/doctrine-doctor-assets/raw/main/demo.gif)

</td>
</tr>
</table>

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

### Quick Start

Zero configuration needed — auto-configured via Symfony Flex.

---

## Installation

```bash
composer require --dev ahmed-bhs/doctrine-doctor
```

Auto-configures via Symfony Flex. Check the **Doctrine Doctor** panel in the Symfony Profiler.

### Configuration (Optional)

Configure thresholds in `config/packages/dev/doctrine_doctor.yaml`:

```yaml
doctrine_doctor:
    analyzers:
        n_plus_one:
            threshold: 3
        slow_query:
            threshold: 50  # milliseconds
```

[Full configuration reference →](docs/CONFIGURATION.md)

---

## Example: N+1 Query Detection

<table>
<tr>
<td width="33%" align="center">**Problem**</td>
<td width="33%" align="center">**Detection**</td>
<td width="33%" align="center">**Solution**</td>
</tr>
<tr>
<td width="33%" valign="top">

**Template triggers lazy loading**

```php
// Controller
$users = $repository
    ->findAll();

// Template
{% for user in users %}
    {{ user.profile.bio }}
{% endfor %}
```

_Triggers 100 queries_

</td>
<td width="33%" valign="top">

**Doctrine Doctor detects N+1**

100 queries instead of 1

Shows exact query count, execution time, and suggests eager loading

_Real-time detection_

</td>
<td width="33%" valign="top">

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

</td>
</tr>
</table>

---

## Documentation

| Document | Description |
|----------|-------------|
| [**Full Analyzers List**](docs/ANALYZERS.md) | Complete catalog of all **66 analyzers** covering performance, security, code quality, and configuration - find the perfect analyzer for your specific needs |
| [**Architecture Guide**](docs/ARCHITECTURE.md) | Deep dive into **system design**, architecture patterns, and technical internals - understand how Doctrine Doctor works under the hood |
| [**Configuration Reference**](docs/CONFIGURATION.md) | Comprehensive guide to **all configuration options** - customize analyzers, thresholds, and outputs to match your workflow |
| [**Template Security**](docs/TEMPLATE_SECURITY.md) | Essential **security best practices** for PHP templates - prevent XSS attacks and ensure safe template rendering |

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## License

MIT License - see [LICENSE](LICENSE) for details.

<div align="right">

---

**Created by [Ahmed EBEN HASSINE](https://github.com/ahmed-bhs)**

<a href="https://github.com/sponsors/ahmed-bhs" target="_blank">
  <img src="https://img.shields.io/static/v1?label=Sponsor&message=GitHub&logo=github&style=for-the-badge&color=blue"
       alt="Sponsor me on GitHub" style="height: 32px !important; border-radius: 5px !important;">
</a>

<a href="https://www.buymeacoffee.com/w6ZhBSGX2" target="_blank">
  <img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png"
       alt="Buy Me A Coffee" style="height: 32px !important; width: 128px !important; border-radius: 5px !important;">
</a>

</div>
